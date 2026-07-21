<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Image re-encodee : decodee puis reecrite par GD.
 *
 * Toute charge embarquee a disparu, toutes les metadonnees aussi — EXIF,
 * geolocalisation, commentaires (06-securite §5.4). C'est ce fichier, et lui
 * seul, qui est archive dans storage/uploads et dont l'empreinte sert a la
 * deduplication.
 */
final class ProcessedImage
{
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
        public readonly string $extension,
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytes,
        /** SHA-256 du fichier RE-ENCODE (01-modele §2). */
        public readonly string $checksum,
    ) {
    }
}
