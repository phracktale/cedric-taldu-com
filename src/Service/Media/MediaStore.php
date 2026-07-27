<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Core\ClockInterface;
use App\Core\RandomInterface;
use App\Core\UploadedFile;
use App\Domain\Locale;
use App\Repository\Admin\MediaAdminRepository;
use RuntimeException;
use Throwable;

/**
 * Enregistrement d'une image, de bout en bout.
 *
 * Valide, re-encode, dedoublonne, range l'original hors webroot, engendre les
 * derives publics, insere la ligne. C'est le seul endroit du projet qui ecrit
 * dans storage/uploads et dans public/media.
 *
 * Trois regles de 06-securite §5 se materialisent ici :
 *
 *  - §5.5 : le nom du fichier est TIRE AU SORT, l'extension deduite du type
 *    reel. Le nom envoye par le client ne sert qu'a etre affiche.
 *  - §5.6 : l'original va hors webroot, en arborescence a deux niveaux ; seuls
 *    les derives re-engendres sont publics.
 *  - §5.4 : ce qui est archive est le fichier RE-ENCODE, pas celui recu.
 *
 * L'ordre des ecritures est choisi pour qu'un echec ne laisse jamais de ligne
 * en base sans fichier : original, puis derives, puis base. L'inverse
 * produirait des vignettes en 404 sur une mediatheque d'apparence normale.
 */
final class MediaStore
{
    public function __construct(
        private readonly UploadValidator $validator,
        private readonly ImageProcessor $processor,
        private readonly MediaAdminRepository $media,
        private readonly RandomInterface $random,
        private readonly ClockInterface $clock,
        /** storage/uploads — HORS webroot. */
        private readonly string $storagePath,
        /** public/media — derives seulement. */
        private readonly string $publicPath,
    ) {
    }

    /**
     * @throws Exception\UploadRejected
     */
    public function store(UploadedFile $file, string $alt = ''): StoredMedia
    {
        $validated = $this->validator->validate($file);

        // Le re-encodage a lieu AVANT de choisir un emplacement definitif :
        // l'empreinte porte sur le fichier re-encode (01-modele §2), et c'est
        // elle qui dit si l'image existe deja.
        $temporary = $this->temporaryPath($validated->extension());
        $processed = $this->processor->reencode($validated, $temporary);

        $existing = $this->media->findByChecksum($processed->checksum);

        if ($existing !== null) {
            // Meme visuel deja present : on jette la copie et on rend
            // l'existant. Deux exports d'une meme photo ne differant que par
            // leurs metadonnees se confondent ici, puisque le re-encodage les a
            // effacees.
            $this->discard($temporary);

            return new StoredMedia((int) $existing['id'], true);
        }

        $basename = $this->random->hex(16);
        $storageFile = $this->storageFileFor($basename, $processed->extension);

        $this->moveIntoPlace($temporary, $storageFile);

        $derivatives = [];

        try {
            $derivatives = $this->processor->derivatives($storageFile, $this->publicPath, $basename);

            $id = $this->media->insert(
                storagePath: $this->relativeStoragePath($basename, $processed->extension),
                publicBasename: $basename,
                mime: $processed->mime,
                width: $processed->width,
                height: $processed->height,
                bytes: $processed->bytes,
                checksum: $processed->checksum,
                originalName: $this->displayName($validated->clientName),
                translations: [Locale::reference()->value => ['alt' => $alt, 'caption' => null]],
                now: $this->clock->now(),
            );
        } catch (Throwable $exception) {
            // Rien a moitie : ni original orphelin, ni derives sans ligne.
            $this->discard($storageFile, ...$derivatives);

            throw $exception;
        }

        return new StoredMedia($id, false);
    }

    /**
     * Remplace l'image d'un media par un nouveau televersement.
     *
     * Le media garde son identifiant et sa place : les couvertures qui pointent
     * vers lui restent valides. On refuse si la nouvelle image existe deja SOUS
     * UN AUTRE media — la contrainte d'unicite de l'empreinte l'interdirait de
     * toute facon, autant le dire clairement.
     *
     * @throws Exception\UploadRejected
     */
    public function replace(int $mediaId, UploadedFile $file): void
    {
        $row = $this->media->findById($mediaId);

        if ($row === null) {
            return;
        }

        $validated = $this->validator->validate($file);
        $temporary = $this->temporaryPath($validated->extension());
        $processed = $this->processor->reencode($validated, $temporary);

        $this->guardAgainstForeignDuplicate($processed->checksum, $mediaId, $temporary);

        $this->swapFile($row, $processed, $temporary, $this->displayName($validated->clientName));
    }

    /**
     * Recadre l'image existante d'un media et regenere ses derives.
     *
     * La zone arrive en fractions ; c'est ImageProcessor qui la convertit en
     * pixels contre les dimensions reelles de l'original. Le format est conserve.
     *
     * @throws Exception\UploadRejected
     */
    public function crop(int $mediaId, CropRegion $region): void
    {
        $row = $this->media->findById($mediaId);

        if ($row === null) {
            return;
        }

        $original = dirname($this->storagePath) . '/' . $row['storage_path'];
        $extension = pathinfo((string) $row['storage_path'], PATHINFO_EXTENSION);
        $temporary = $this->temporaryPath($extension === '' ? 'jpg' : $extension);

        $processed = $this->processor->crop($original, $region, $temporary);

        $this->guardAgainstForeignDuplicate($processed->checksum, $mediaId, $temporary);

        // Le recadrage ne touche pas au nom d'origine : c'est la meme image, cadree.
        $originalName = $row['original_name'] === null ? null : (string) $row['original_name'];
        $this->swapFile($row, $processed, $temporary, $originalName);
    }

    /**
     * Efface un media et tous ses fichiers.
     *
     * Les derives d'abord, l'original ensuite, la ligne en dernier : si
     * l'effacement s'interrompt, il reste une ligne dont les fichiers manquent
     * — visible et rattrapable — plutot que des fichiers dont plus rien ne
     * garde la trace.
     */
    public function remove(int $mediaId): void
    {
        $row = $this->media->findById($mediaId);

        if ($row === null) {
            return;
        }

        $basename = (string) $row['public_basename'];

        foreach (glob($this->publicPath . '/' . $basename . '-*') ?: [] as $derivative) {
            $this->discard($derivative);
        }

        $this->discard(dirname($this->storagePath) . '/' . $row['storage_path']);
        $this->media->delete($mediaId);
    }

    // -------------------------------------------------------------- interne

    /**
     * Echange le fichier d'un media en place : regenere les derives, purge les
     * orphelins, range le nouvel original, met a jour la ligne.
     *
     * Partage par replace() et crop(). L'ordre — derives d'abord, original
     * ensuite, ligne en dernier — suit la meme logique que store() : jamais de
     * ligne qui pointe un fichier absent.
     *
     * @param array<string, mixed> $row
     */
    private function swapFile(array $row, ProcessedImage $processed, string $temporary, ?string $originalName): void
    {
        $mediaId = (int) $row['id'];
        $basename = (string) $row['public_basename'];

        // 1. Regenerer les derives depuis le nouvel original (memes noms, ecrases).
        try {
            $fresh = $this->processor->derivatives($temporary, $this->publicPath, $basename);
        } catch (Throwable $exception) {
            $this->discard($temporary);

            throw $exception;
        }

        // 2. Purger les orphelins : une image devenue plus petite ne produit plus
        //    les grandes largeurs, dont les derives n'ont alors plus de source.
        $keep = array_map('basename', $fresh);

        foreach (glob($this->publicPath . '/' . $basename . '-*') ?: [] as $derivative) {
            if (!in_array(basename($derivative), $keep, true)) {
                $this->discard($derivative);
            }
        }

        // 3. Ranger le nouvel original ; retirer l'ancien s'il changeait d'extension.
        $newStorage = $this->storageFileFor($basename, $processed->extension);
        $oldStorage = dirname($this->storagePath) . '/' . $row['storage_path'];

        if ($oldStorage !== $newStorage) {
            $this->discard($oldStorage);
        }

        // rename() refuse une destination existante sous Windows : on l'efface d'abord.
        $this->discard($newStorage);
        $this->moveIntoPlace($temporary, $newStorage);

        // 4. Mettre a jour la ligne (le depot reinitialise le point focal).
        $this->media->updateFile(
            mediaId: $mediaId,
            storagePath: $this->relativeStoragePath($basename, $processed->extension),
            mime: $processed->mime,
            width: $processed->width,
            height: $processed->height,
            bytes: $processed->bytes,
            checksum: $processed->checksum,
            originalName: $originalName,
        );
    }

    /**
     * Refuse une image deja presente sous un AUTRE media.
     *
     * L'empreinte est unique en base : deux medias ne peuvent pas partager le
     * meme visuel. On le dit clairement plutot que de laisser la contrainte SQL
     * lever une erreur opaque.
     */
    private function guardAgainstForeignDuplicate(string $checksum, int $mediaId, string $temporary): void
    {
        $existing = $this->media->findByChecksum($checksum);

        if ($existing !== null && (int) $existing['id'] !== $mediaId) {
            $this->discard($temporary);

            throw Exception\UploadRejected::because(UploadRejection::Duplicate);
        }
    }

    /**
     * Arborescence a deux niveaux (01-modele §2) : quelques milliers d'images
     * dans un seul repertoire ralentissent toute operation du systeme de
     * fichiers, sauvegarde comprise.
     */
    private function storageFileFor(string $basename, string $extension): string
    {
        $directory = $this->storagePath . '/' . substr($basename, 0, 2) . '/' . substr($basename, 2, 2);

        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire de stockage.');
        }

        return $directory . '/' . $basename . '.' . $extension;
    }

    private function relativeStoragePath(string $basename, string $extension): string
    {
        return 'uploads/' . substr($basename, 0, 2) . '/' . substr($basename, 2, 2)
            . '/' . $basename . '.' . $extension;
    }

    private function temporaryPath(string $extension): string
    {
        $directory = $this->storagePath . '/tmp';

        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire temporaire.');
        }

        return $directory . '/' . $this->random->hex(16) . '.' . $extension;
    }

    private function moveIntoPlace(string $from, string $to): void
    {
        // rename() plutot que move_uploaded_file() : le fichier deplace n'est
        // PAS celui que PHP a recu, c'est celui que GD vient d'ecrire.
        if (!rename($from, $to)) {
            $this->discard($from);

            throw new RuntimeException('Impossible de ranger l’image téléversée.');
        }
    }

    /**
     * Nom d'origine conserve comme METADONNEE (06-securite §5.5).
     *
     * Ramene a son nom de base et tronque : il ne sert qu'a ce que l'artiste
     * reconnaisse son image dans la mediatheque. Il n'entre JAMAIS dans un
     * chemin — d'ou basename(), qui neutralise « ../ » aussi bien qu'un chemin
     * absolu envoye par un client hostile.
     */
    private function displayName(string $clientName): ?string
    {
        $name = trim(basename(str_replace('\\', '/', $clientName)));

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        return mb_substr($name, 0, 255, 'UTF-8');
    }

    private function discard(string ...$files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
