<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Domain\Catalog\Media;
use GdImage;
use RuntimeException;

/**
 * Images de remplacement pour le jeu de demonstration.
 *
 * Les visuels reels des œuvres n'existent pas encore. Plutot qu'un gris uni ou
 * un service tiers — interdit par la CSP —, on engendre la TRAME POINTILLEE du
 * site, aux dimensions reelles de chaque œuvre. La preproduction a alors la
 * bonne forme, les bons rapports d'aspect et le bon poids de page, sans
 * pretendre montrer un travail qui n'est pas la.
 *
 * Ce generateur ne sert QUE au jeu de demonstration. Le pipeline de
 * televersement — re-encodage GD, suppression des metadonnees, deduplication —
 * appartient au lot 2 (06-securite §5).
 */
final class PlaceholderGenerator
{
    private const PAPIER = [250, 249, 246];
    private const ENCRE = [22, 21, 19];

    public function __construct(private readonly string $mediaPath)
    {
    }

    /**
     * Engendre tous les derives d'un media, dans tous les formats declares.
     *
     * @return list<string> fichiers ecrits
     */
    public function generate(string $basename, int $width, int $height): array
    {
        if (!is_dir($this->mediaPath) && !mkdir($this->mediaPath, 0o775, true) && !is_dir($this->mediaPath)) {
            throw new RuntimeException('Impossible de créer ' . $this->mediaPath);
        }

        $written = [];

        foreach (Media::WIDTHS as $target) {
            if ($target > $width && $target !== Media::WIDTHS[0]) {
                continue;
            }

            $targetHeight = max(1, (int) round($target * $height / $width));
            $image = $this->draw($target, $targetHeight);

            foreach (Media::FORMATS as $format) {
                $file = $this->mediaPath . '/' . $basename . '-' . $target . '.' . $format;
                $this->write($image, $file, $format);
                $written[] = $file;
            }

            imagedestroy($image);
        }

        return $written;
    }

    /**
     * Fond papier, trame de points reguliere, deux nuees plus denses : la
     * signature graphique du site, en attendant les vraies encres.
     */
    private function draw(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));

        $papier = imagecolorallocate($image, ...self::PAPIER);

        if ($papier === false) {
            throw new RuntimeException('Palette GD épuisée.');
        }

        imagefilledrectangle($image, 0, 0, $width, $height, $papier);

        // Pas de trame proportionnel a la largeur : la densite reste la meme
        // d'un derive a l'autre, sinon la vignette parait plus sombre.
        //
        // Une trame plus fine serait plus fidele au dessin, mais c'est du bruit
        // haute frequence que JPEG compresse tres mal : a 90 points de large,
        // un derive de 1024 px pesait 425 Ko pour une image de remplacement.
        $pas = max(4, (int) round($width / 55));

        for ($y = 0; $y < $height; $y += $pas) {
            for ($x = 0; $x < $width; $x += $pas) {
                $densite = $this->densite($x / $width, $y / $height);

                if ($densite <= 0.02) {
                    continue;
                }

                $alpha = max(0, min(127, (int) round(127 - 127 * min(1.0, $densite))));
                $encre = imagecolorallocatealpha($image, ...[...self::ENCRE, $alpha]);

                if ($encre === false) {
                    continue;
                }

                imagefilledellipse($image, $x, $y, 3, 3, $encre);
            }
        }

        return $image;
    }

    /**
     * Deux nuees gaussiennes, aux memes positions que les degrades radiaux des
     * maquettes.
     */
    private function densite(float $x, float $y): float
    {
        $nuee = static fn (float $cx, float $cy, float $rayon): float
            => exp(-((($x - $cx) ** 2 + ($y - $cy) ** 2) / (2 * $rayon ** 2)));

        return min(1.0, 0.10 + 0.55 * $nuee(0.32, 0.38, 0.22) + 0.45 * $nuee(0.66, 0.62, 0.20));
    }

    private function write(GdImage $image, string $file, string $format): void
    {
        $ok = match ($format) {
            'jpg' => imagejpeg($image, $file, 74),
            'webp' => imagewebp($image, $file, 72),
            'avif' => function_exists('imageavif') && imageavif($image, $file, 70),
            default => false,
        };

        if ($ok !== true) {
            throw new RuntimeException('Écriture impossible : ' . $file);
        }
    }
}
