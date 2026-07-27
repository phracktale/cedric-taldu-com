<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Domain\Catalog\Media;
use App\Service\Media\Exception\UploadRejected;
use ErrorException;
use GdImage;
use RuntimeException;
use Throwable;

/**
 * Re-encodage GD et generation des derives.
 *
 * 06-securite §5.4 : « RE-ENCODAGE SYSTEMATIQUE : l'image est decodee puis
 * reecrite. Cela detruit toute charge utile embarquee (polyglotte GIFAR, PHP en
 * commentaire EXIF) et supprime les metadonnees, y compris la geolocalisation. »
 *
 * C'est la SEULE barriere contre un JPEG parfaitement valide portant une charge :
 * finfo et getimagesize le declarent bon, et ils ont raison. Le fichier ecrit
 * ici ne contient que les pixels — GD ne recopie aucun segment qu'il n'a pas
 * lui-meme produit.
 *
 * Aucun « @ » nulle part (src/CLAUDE.md). Les alertes de GD sur un fichier
 * tronque sont PROMUES en exception par un gestionnaire d'erreurs pose le temps
 * de l'appel : masquer et promouvoir ne sont pas la meme chose — la premiere
 * laisse continuer avec une valeur fausse, la seconde arrete net.
 */
final class ImageProcessor
{
    /** Qualites de sortie, reprises du generateur de remplissage du lot 1. */
    private const QUALITY_JPEG = 82;
    private const QUALITY_WEBP = 80;
    private const COMPRESSION_PNG = 6;

    /**
     * Decode l'image validee et la reecrit a l'emplacement demande.
     *
     * @throws UploadRejected si le fichier est illisible malgre un en-tete valide
     */
    public function reencode(ValidatedImage $source, string $destination): ProcessedImage
    {
        $image = $this->decode($source);

        try {
            $this->write($image, $destination, $source->extension());
        } catch (Throwable $exception) {
            imagedestroy($image);
            $this->discard($destination);

            throw $exception;
        }

        imagedestroy($image);

        $checksum = hash_file('sha256', $destination);

        if ($checksum === false) {
            $this->discard($destination);

            throw UploadRejected::because(UploadRejection::Corrupt, 'empreinte illisible');
        }

        return new ProcessedImage(
            path: $destination,
            mime: $source->mime,
            extension: $source->extension(),
            width: $source->width,
            height: $source->height,
            bytes: (int) filesize($destination),
            checksum: $checksum,
        );
    }

    /**
     * Engendre les derives publics : Media::WIDTHS x Media::FORMATS.
     *
     * Les deux constantes du domaine font autorite (01-modele §2) : le gabarit
     * `picture` engendre ses <source> a partir des memes valeurs, et un decalage
     * produirait des balises pointant des fichiers inexistants.
     *
     * @return list<string> fichiers ecrits
     */
    public function derivatives(string $sourcePath, string $directory, string $basename): array
    {
        $source = $this->decodeFile($sourcePath);
        $width = imagesx($source);
        $height = imagesy($source);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            imagedestroy($source);

            throw new RuntimeException('Impossible de créer ' . $directory);
        }

        $written = [];

        try {
            foreach ($this->targetWidths($width) as $target) {
                // max(1, ...) n'est pas une precaution decorative : un original
                // tres large et tres plat — un panoramique de 2400 x 3 — donne
                // une hauteur arrondie a zero pour les petites largeurs, et GD
                // refuse une image de hauteur nulle.
                $targetHeight = max(1, (int) round($target * $height / $width));
                $resized = $this->resize($source, max(1, $target), $targetHeight);

                try {
                    foreach (Media::FORMATS as $format) {
                        $file = $directory . '/' . $basename . '-' . $target . '.' . $format;
                        $this->write($resized, $file, $format);
                        $written[] = $file;
                    }
                } finally {
                    imagedestroy($resized);
                }
            }
        } catch (Throwable $exception) {
            // Un jeu de derives incomplet est pire qu'aucun : le gabarit
            // annoncerait des <source> qui rendent 404.
            foreach ($written as $file) {
                $this->discard($file);
            }

            imagedestroy($source);

            throw $exception;
        }

        imagedestroy($source);

        return $written;
    }

    /**
     * Recadre l'original et reecrit la zone retenue, dans le format d'origine.
     *
     * La zone arrive en fractions (CropRegion) : elle est convertie en pixels
     * contre les dimensions REELLES de l'image, jamais celles annoncees par le
     * client. Comme reencode(), c'est GD qui redecode et reecrit — la sortie ne
     * porte donc aucune metadonnee ni charge (06-securite §5.4).
     *
     * @throws UploadRejected si le fichier est illisible ou la decoupe impossible
     */
    public function crop(string $sourcePath, CropRegion $region, string $destination): ProcessedImage
    {
        $mime = $this->mimeOf($sourcePath);
        $source = $this->decodeFile($sourcePath, $mime);
        $rectangle = $region->toPixels(imagesx($source), imagesy($source));

        $cropped = $this->promotingWarnings(
            static fn (): GdImage|false => imagecrop($source, $rectangle),
        );
        imagedestroy($source);

        if (!$cropped instanceof GdImage) {
            throw UploadRejected::because(UploadRejection::Corrupt, 'recadrage impossible');
        }

        // Meme precaution que decodeFile : sans quoi la transparence d'un PNG se
        // melange au fond a l'ecriture.
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);

        $extension = self::EXTENSIONS[$mime];

        try {
            $this->write($cropped, $destination, $extension);
        } catch (Throwable $exception) {
            imagedestroy($cropped);
            $this->discard($destination);

            throw $exception;
        }

        $width = imagesx($cropped);
        $height = imagesy($cropped);
        imagedestroy($cropped);

        $checksum = hash_file('sha256', $destination);

        if ($checksum === false) {
            $this->discard($destination);

            throw UploadRejected::because(UploadRejection::Corrupt, 'empreinte illisible');
        }

        return new ProcessedImage(
            path: $destination,
            mime: $mime,
            extension: $extension,
            width: $width,
            height: $height,
            bytes: (int) filesize($destination),
            checksum: $checksum,
        );
    }

    // ------------------------------------------------------------- interne

    /** @var array<string, string> type MIME accepte vers extension d'ecriture */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private function mimeOf(string $path): string
    {
        $size = getimagesize($path);
        $mime = $size === false ? '' : $size['mime'];

        if (!isset(self::EXTENSIONS[$mime])) {
            throw UploadRejected::because(UploadRejection::Corrupt, 'format non pris en charge');
        }

        return $mime;
    }

    /**
     * Largeurs reellement produites.
     *
     * Aucun derive n'agrandit l'original : cela n'ajoute pas d'information et
     * fait telecharger plus d'octets pour un resultat plus flou. Une image plus
     * petite que la plus petite largeur garde malgre tout un derive, sans quoi
     * elle n'aurait aucune source. Meme regle que Media::availableWidths().
     *
     * @return list<int>
     */
    private function targetWidths(int $width): array
    {
        $widths = array_values(array_filter(
            Media::WIDTHS,
            static fn (int $candidate): bool => $candidate <= $width,
        ));

        return $widths === [] ? [Media::WIDTHS[0]] : $widths;
    }

    private function decode(ValidatedImage $source): GdImage
    {
        return $this->decodeFile($source->path, $source->mime);
    }

    private function decodeFile(string $path, ?string $mime = null): GdImage
    {
        if ($mime === null) {
            $size = getimagesize($path);
            $mime = $size === false ? '' : $size['mime'];
        }

        $image = $this->promotingWarnings(static fn (): GdImage|false => match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => false,
        });

        if (!$image instanceof GdImage) {
            throw UploadRejected::because(UploadRejection::Corrupt, 'décodage impossible');
        }

        // Sans ces deux appels, la transparence d'un PNG se perd a l'ecriture :
        // GD melange l'alpha au lieu de le conserver, et tout ce qui etait
        // transparent devient noir.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function resize(GdImage $source, int $width, int $height): GdImage
    {
        $resized = imagecreatetruecolor($width, $height);

        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        // imagecopyresampled et non imagecopyresized : le second n'interpole
        // pas et rend un escalier sur toute reduction.
        imagecopyresampled(
            $resized,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($source),
            imagesy($source),
        );

        return $resized;
    }

    private function write(GdImage $image, string $file, string $format): void
    {
        // JPEG n'a pas de canal alpha : lui laisser savealpha produit un fichier
        // aux couleurs delavees sur certaines versions de GD.
        if ($format === 'jpg') {
            imagealphablending($image, true);
            imagesavealpha($image, false);
        }

        $written = $this->promotingWarnings(static fn (): bool => match ($format) {
            'jpg' => imagejpeg($image, $file, self::QUALITY_JPEG),
            'png' => imagepng($image, $file, self::COMPRESSION_PNG),
            'webp' => imagewebp($image, $file, self::QUALITY_WEBP),
            default => false,
        });

        if ($written !== true) {
            throw UploadRejected::because(UploadRejection::Corrupt, 'écriture impossible');
        }
    }

    /**
     * Efface un fichier partiel sans se plaindre s'il n'existe pas.
     *
     * Sans ce nettoyage, storage/uploads et public/media se remplissent de
     * fragments qu'aucune ligne de base ne reference, et que personne ne
     * nettoie jamais.
     */
    private function discard(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Execute un appel GD en PROMOUVANT ses alertes en exception.
     *
     * GD signale un fichier tronque par un E_WARNING et rend false. Un « @ »
     * masquerait l'alerte — src/CLAUDE.md l'interdit, et a raison : masquer
     * laisse continuer avec une valeur fausse. Ici l'alerte devient une
     * exception typee, donc un refus explicite, et le gestionnaire est retire
     * des la fin de l'appel.
     *
     * @template T
     * @param  callable(): T $call
     * @return T
     */
    private function promotingWarnings(callable $call): mixed
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            return $call();
        } catch (ErrorException $exception) {
            throw UploadRejected::because(UploadRejection::Corrupt, $exception->getMessage());
        } finally {
            restore_error_handler();
        }
    }
}
