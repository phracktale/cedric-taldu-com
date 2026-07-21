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
