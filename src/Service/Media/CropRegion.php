<?php

declare(strict_types=1);

namespace App\Service\Media;

use InvalidArgumentException;

/**
 * Zone de recadrage, exprimee en FRACTIONS de l'image (0..1).
 *
 * Le navigateur affiche l'image a une taille quelconque ; il ne renvoie donc
 * pas des pixels mais des proportions. Le serveur seul les convertit en pixels,
 * contre les dimensions REELLES de l'original — jamais celles annoncees par le
 * client (autorite serveur, CLAUDE.md §7).
 */
final class CropRegion
{
    /** Tolerance d'arrondi : une zone qui deborde d'un cheveu est rognee, pas refusee. */
    private const EPSILON = 0.005;

    private function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
    ) {
    }

    public static function fromFractions(float $x, float $y, float $width, float $height): self
    {
        if ($width <= 0.0 || $height <= 0.0) {
            throw new InvalidArgumentException('La zone de recadrage doit avoir une surface.');
        }

        if ($x < 0.0 || $y < 0.0) {
            throw new InvalidArgumentException('La zone de recadrage sort de l’image.');
        }

        if ($x + $width > 1.0 + self::EPSILON || $y + $height > 1.0 + self::EPSILON) {
            throw new InvalidArgumentException('La zone de recadrage sort de l’image.');
        }

        return new self($x, $y, $width, $height);
    }

    /**
     * Rectangle en pixels, borne aux dimensions de l'image.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function toPixels(int $imageWidth, int $imageHeight): array
    {
        $x = max(0, min($imageWidth - 1, (int) round($this->x * $imageWidth)));
        $y = max(0, min($imageHeight - 1, (int) round($this->y * $imageHeight)));
        $width = max(1, (int) round($this->width * $imageWidth));
        $height = max(1, (int) round($this->height * $imageHeight));

        // Un arrondi peut pousser la zone au-dela du bord : GD refuse une
        // decoupe qui sort du cadre, on la rogne au dernier pixel utile.
        if ($x + $width > $imageWidth) {
            $width = $imageWidth - $x;
        }

        if ($y + $height > $imageHeight) {
            $height = $imageHeight - $y;
        }

        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }
}
