<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Core\RandomInterface;
use App\Core\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

/**
 * Documents PDF des pages — le livret à télécharger (retour client du
 * 2026-09-29).
 *
 * Un PDF ne se ré-encode pas comme une image : il est donc CONTRÔLÉ (signature
 * « %PDF- » en tête, type détecté par finfo, taille bornée) puis copié sous un
 * nom tiré au hasard, HORS du webroot (storage/documents). Le nom d'origine
 * n'entre jamais dans un chemin ; le fichier n'est servi que par
 * DocumentController, avec son type et nosniff (06-securite §5).
 */
final class DocumentStore
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const DIRECTORY = 'documents';

    /**
     * @param string $storageRoot racine de storage/ (le chemin rangé en base lui est relatif)
     */
    public function __construct(
        private readonly string $storageRoot,
        private readonly RandomInterface $random,
    ) {
    }

    /**
     * @return string chemin relatif à storage/ (« documents/{hex}.pdf »)
     *
     * @throws InvalidArgumentException message destiné à l'artiste
     */
    public function store(UploadedFile $file): string
    {
        if ($file->error !== UPLOAD_ERR_OK || !is_file($file->path)) {
            throw new InvalidArgumentException('Le document n’a pas pu être reçu. Réessayez.');
        }

        if ($file->size > self::MAX_BYTES || (int) filesize($file->path) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Le document dépasse 20 Mo.');
        }

        $entete = (string) file_get_contents($file->path, false, null, 0, 5);
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($file->path);

        if ($entete !== '%PDF-' || $type !== 'application/pdf') {
            throw new InvalidArgumentException('Le document doit être un fichier PDF.');
        }

        $dossier = $this->storageRoot . '/' . self::DIRECTORY;
        if (!is_dir($dossier) && !mkdir($dossier, 0o770, true) && !is_dir($dossier)) {
            throw new RuntimeException('Impossible de créer le dossier des documents.');
        }

        $relatif = self::DIRECTORY . '/' . $this->random->hex(16) . '.pdf';

        if (!copy($file->path, $this->storageRoot . '/' . $relatif)) {
            throw new RuntimeException('Impossible de ranger le document.');
        }

        return $relatif;
    }

    /**
     * Chemin absolu d'un document rangé, ou null s'il n'existe pas (ou si le
     * chemin n'a pas la forme que store() produit).
     */
    public function path(?string $relative): ?string
    {
        if ($relative === null || preg_match('#^documents/[0-9a-f]{32}\.pdf$#D', $relative) !== 1) {
            return null;
        }

        $chemin = $this->storageRoot . '/' . $relative;

        return is_file($chemin) ? $chemin : null;
    }

    public function remove(?string $relative): void
    {
        $chemin = $this->path($relative);

        if ($chemin !== null) {
            unlink($chemin);
        }
    }
}
