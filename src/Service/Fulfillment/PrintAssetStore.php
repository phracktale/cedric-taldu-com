<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use App\Core\RandomInterface;
use App\Core\UploadedFile;
use App\Service\Fulfillment\Exception\PrintAssetRejected;
use RuntimeException;

/**
 * Rangement du fichier d'impression haute définition d'une œuvre.
 *
 * Deux différences essentielles avec MediaStore :
 *
 *  1. AUCUN ré-encodage. L'impression exige la pleine résolution et le profil
 *     colorimétrique d'origine — on conserve les octets reçus tels quels. C'est
 *     tenable parce que la source est l'ARTISTE authentifié, pas un visiteur
 *     public ; le risque de charge embarquée est celui d'un poste de confiance.
 *  2. Types acceptés = ceux que Prodigi imprime : JPEG, PNG, PDF.
 *
 * Comme les originaux média, le fichier est rangé HORS webroot, en arborescence
 * à deux niveaux, sous un nom TIRÉ AU SORT (06-securite §5.5) — le nom du client
 * ne sert jamais à construire un chemin.
 */
final class PrintAssetStore
{
    /** 150 Mo : un tirage haute définition est lourd, mais pas illimité. */
    private const MAX_BYTES = 157_286_400;

    /** @var array<string, string> type MIME imprimable vers extension */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly RandomInterface $random,
        /** storage/print — HORS webroot. */
        private readonly string $printPath,
    ) {
    }

    /**
     * @throws PrintAssetRejected si le fichier est absent, vide, trop lourd ou d'un type non imprimable
     */
    public function store(UploadedFile $file): PrintAsset
    {
        if ($file->error !== UPLOAD_ERR_OK) {
            throw PrintAssetRejected::because('le transfert a été interrompu');
        }

        if (!is_file($file->path)) {
            throw PrintAssetRejected::because('aucun fichier reçu');
        }

        $bytes = (int) filesize($file->path);

        if ($bytes <= 0) {
            throw PrintAssetRejected::because('fichier vide');
        }

        if ($bytes > self::MAX_BYTES) {
            throw PrintAssetRejected::because('dépasse la taille maximale de 150 Mo');
        }

        $mime = $this->detect($file->path);

        if (!isset(self::TYPES[$mime])) {
            throw PrintAssetRejected::because('type non imprimable (attendu JPEG, PNG ou PDF)');
        }

        $basename = $this->random->hex(16);
        $extension = self::TYPES[$mime];
        $destination = $this->storageFileFor($basename, $extension);

        if (!copy($file->path, $destination)) {
            throw new RuntimeException('Impossible de ranger le fichier d’impression.');
        }

        return new PrintAsset($this->relativePath($basename, $extension), $mime);
    }

    public function remove(string $relativePath): void
    {
        $absolute = $this->absolutePathFor($relativePath);

        if (is_file($absolute)) {
            unlink($absolute);
        }
    }

    /**
     * Chemin absolu du fichier à partir du chemin relatif stocké en base.
     *
     * Le chemin relatif vient de NOTRE base (jamais du client) et suit toujours
     * la forme « print/ab/cd/<hex>.<ext> » — aucun segment issu d'une entrée.
     */
    public function absolutePathFor(string $relativePath): string
    {
        return dirname($this->printPath) . '/' . $relativePath;
    }

    // -------------------------------------------------------------- interne

    private function detect(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new RuntimeException('finfo indisponible.');
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? '' : $mime;
    }

    private function storageFileFor(string $basename, string $extension): string
    {
        $directory = $this->printPath . '/' . substr($basename, 0, 2) . '/' . substr($basename, 2, 2);

        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire d’impression.');
        }

        return $directory . '/' . $basename . '.' . $extension;
    }

    private function relativePath(string $basename, string $extension): string
    {
        return 'print/' . substr($basename, 0, 2) . '/' . substr($basename, 2, 2)
            . '/' . $basename . '.' . $extension;
    }
}
