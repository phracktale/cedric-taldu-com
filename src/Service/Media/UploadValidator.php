<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Core\UploadedFile;
use App\Service\Media\Exception\UploadRejected;
use finfo;

/**
 * Premiere barriere du televersement (06-securite §5, points 1 a 3).
 *
 * L'ordre des controles n'est pas arbitraire : chacun protege le suivant.
 *
 *  1. Le code d'erreur de PHP, avant de toucher au fichier.
 *  2. La TAILLE, avant toute lecture du contenu — sinon un fichier de deux
 *     gigaoctets serait parcouru pour decouvrir qu'il est trop gros.
 *  3. Le TYPE REEL, par finfo_file ET getimagesize, jamais par l'extension ni
 *     par le Content-Type du client (§5.2).
 *  4. Les DIMENSIONS et le NOMBRE DE PIXELS, avant tout decodage : c'est la
 *     seule facon d'arreter une « bombe de decompression », dont le fichier tient
 *     en soixante octets et l'image en dix gigaoctets (§5.3).
 *
 * Ce qui passe ici n'est pas repute sain : c'est le re-encodage qui neutralise
 * les charges embarquees.
 */
final class UploadValidator
{
    /** 06-securite §5.3 : taille maximale de 25 Mo. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** 06-securite §5.3 : 12 000 px de cote au plus. */
    public const MAX_DIMENSION = 12000;

    /**
     * Budget de pixels, plus contraignant que la borne de dimension.
     *
     * GD travaille en couleurs vraies : quatre octets par pixel, pour l'image
     * source ET pour le derive en cours d'ecriture. Avec `memory_limit = 256M`
     * (docker/php/php.ini), quarante megapixels occupent 160 Mo, auxquels
     * s'ajoute le plus grand derive (2400 px de large, une trentaine de Mo).
     *
     * La borne de 12 000 x 12 000 de la spec vaut 144 megapixels, soit 576 Mo :
     * elle passerait ce validateur pour mourir en cours de decodage, sur une
     * page blanche. Les deux bornes sont donc conservees, chacune arretant ce
     * que l'autre laisse passer — un ruban de 50 000 x 100 ne fait que cinq
     * megapixels.
     */
    public const MAX_PIXELS = 40_000_000;

    /**
     * 06-securite §5.1 : « Types acceptes : image/jpeg, image/png, image/webp
     * uniquement. SVG interdit (vecteur de XSS). »
     *
     * L'extension est DEDUITE du type reel (§5.5), elle ne vient jamais du nom
     * envoye par le client.
     */
    public const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Types au sens de getimagesize, pour recouper finfo. Un fichier dont les
     * deux verdicts divergent est un fichier construit pour tromper l'un des
     * deux : il est refuse.
     */
    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    public function validate(UploadedFile $file): ValidatedImage
    {
        $this->rejectPhpErrors($file);

        // La taille annoncee par PHP ET la taille reelle : la premiere vient de
        // l'en-tete multipart, la seconde du disque.
        $bytes = (int) filesize($file->path);

        if ($file->size > self::MAX_BYTES || $bytes > self::MAX_BYTES) {
            throw UploadRejected::because(UploadRejection::TooHeavy, (string) max($file->size, $bytes));
        }

        if ($bytes === 0) {
            throw UploadRejected::because(UploadRejection::Empty);
        }

        $mime = $this->detectMime($file->path);
        $dimensions = $this->detectDimensions($file->path, $mime);

        [$width, $height] = $dimensions;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw UploadRejected::because(UploadRejection::TooLarge, $width . 'x' . $height);
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw UploadRejected::because(UploadRejection::TooManyPixels, $width . 'x' . $height);
        }

        $this->rejectTruncated($file->path, $mime, $bytes);

        return new ValidatedImage(
            path: $file->path,
            mime: $mime,
            width: $width,
            height: $height,
            bytes: $bytes,
            clientName: $file->clientName,
        );
    }

    // ------------------------------------------------------------- interne

    private function rejectPhpErrors(UploadedFile $file): void
    {
        if ($file->error === UPLOAD_ERR_INI_SIZE || $file->error === UPLOAD_ERR_FORM_SIZE) {
            // Dire « fichier manquant » enverrait l'artiste chercher un probleme
            // qui n'existe pas : PHP a bien recu le fichier, il l'a rejete.
            throw UploadRejected::because(UploadRejection::TooHeavy, 'limite de PHP');
        }

        if ($file->isMissing()) {
            throw UploadRejected::because(UploadRejection::Missing);
        }

        if ($file->error !== UPLOAD_ERR_OK) {
            throw UploadRejected::because(UploadRejection::Failed, 'UPLOAD_ERR_' . $file->error);
        }

        if ($file->path === '' || !is_file($file->path)) {
            throw UploadRejected::because(UploadRejection::Missing, 'fichier temporaire absent');
        }
    }

    /**
     * Refuse un fichier dont la fin manque.
     *
     * GD decode un JPEG tronque SANS LA MOINDRE ALERTE : il comble les lignes
     * absentes en gris et rend une image parfaitement valide. Il n'y a donc rien
     * a rattraper au decodage — verifie sur ce projet, a 30 %, 55 % et 90 % du
     * fichier. Sans ce controle, un transfert interrompu publierait une œuvre a
     * moitie grise, et personne ne s'en apercevrait avant le client.
     *
     * Chaque format porte une marque de fin sans ambiguite : c'est elle qu'on
     * lit, sur les derniers octets, sans decoder quoi que ce soit.
     */
    private function rejectTruncated(string $path, string $mime, int $bytes): void
    {
        $tail = self::tail($path, 16);

        $complete = match ($mime) {
            // JPEG : marqueur EOI.
            'image/jpeg' => str_ends_with($tail, "\xFF\xD9"),
            // PNG : bloc IEND, toujours les douze derniers octets.
            'image/png' => str_contains($tail, 'IEND'),
            // WebP : le conteneur RIFF annonce sa taille en octets 4 a 7, hors
            // les huit premiers.
            'image/webp' => self::riffLength($path) + 8 <= $bytes,
            default => true,
        };

        if (!$complete) {
            throw UploadRejected::because(UploadRejection::Corrupt, 'fin de fichier absente');
        }
    }

    /**
     * @param positive-int $length
     */
    private static function tail(string $path, int $length): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw UploadRejected::because(UploadRejection::Missing, 'lecture impossible');
        }

        fseek($handle, -$length, SEEK_END);
        $tail = (string) fread($handle, $length);
        fclose($handle);

        return $tail;
    }

    private static function riffLength(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        fseek($handle, 4);
        $header = (string) fread($handle, 4);
        fclose($handle);

        $unpacked = unpack('V', $header);

        return is_array($unpacked) ? (int) $unpacked[1] : 0;
    }

    private function detectMime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        if (!is_string($mime) || !isset(self::ALLOWED_TYPES[$mime])) {
            throw UploadRejected::because(
                UploadRejection::ForbiddenType,
                is_string($mime) ? $mime : 'type indéterminé',
            );
        }

        return $mime;
    }

    /**
     * @return array{int, int}
     */
    private function detectDimensions(string $path, string $mime): array
    {
        // getimagesize ne leve pas : elle rend false sur un fichier qui n'est
        // pas une image. Le cas est deja couvert par detectMime(), mais un
        // fichier peut avoir un en-tete credible et un corps illisible.
        $size = getimagesize($path);

        if ($size === false) {
            throw UploadRejected::because(UploadRejection::ForbiddenType, 'dimensions illisibles');
        }

        // Recoupement des deux verdicts. Un fichier que finfo dit JPEG et que
        // getimagesize dit PNG a ete fabrique pour tromper l'un des deux.
        if ((self::IMAGE_TYPES[$size[2]] ?? null) !== $mime) {
            throw UploadRejected::because(UploadRejection::ForbiddenType, 'type incohérent');
        }

        return [$size[0], $size[1]];
    }
}
