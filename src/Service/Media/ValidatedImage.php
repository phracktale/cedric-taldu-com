<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Fichier dont on sait qu'il EST une image d'un type accepte, de dimensions
 * tenables.
 *
 * Ce n'est pas un fichier repute sain : un JPEG parfaitement valide peut porter
 * du PHP dans un segment de commentaire. La seconde barriere est le
 * re-encodage (06-securite §5.4), et le type distinct existe pour qu'on ne
 * puisse pas confondre « valide » et « traite ».
 */
final class ValidatedImage
{
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytes,
        /** Nom envoye par le client, conserve comme METADONNEE seulement. */
        public readonly string $clientName,
    ) {
    }

    public function extension(): string
    {
        return UploadValidator::ALLOWED_TYPES[$this->mime] ?? 'jpg';
    }
}
