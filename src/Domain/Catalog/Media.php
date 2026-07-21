<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;
use App\Domain\Translations;

/**
 * Image du catalogue.
 *
 * 01-modele-de-donnees §2 : les derives sont generes a l'upload dans
 * public/media/<basename>-<largeur>.<ext>. La base ne stocke PAS leur liste,
 * parce qu'elle est deterministe — c'est cette classe qui la determine, et le
 * generateur d'images du lot 2 produira exactement ces fichiers.
 */
final class Media
{
    /** Largeurs de derives, en pixels. */
    public const WIDTHS = [320, 640, 1024, 1600, 2400];

    /**
     * Formats, DU PLUS EFFICACE AU REPLI. L'ordre compte : <picture> retient la
     * premiere source que le navigateur comprend.
     */
    public const FORMATS = ['avif', 'webp', 'jpg'];

    /**
     * @param Translations<MediaTranslation> $translations
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicBasename,
        public readonly string $mime,
        public readonly int $width,
        public readonly int $height,
        public readonly ?int $focalX,
        public readonly ?int $focalY,
        public readonly Translations $translations,
    ) {
    }

    public function derivativeFilename(int $width, string $format): string
    {
        return $this->publicBasename . '-' . $width . '.' . $format;
    }

    /**
     * Largeurs reellement produites.
     *
     * Aucun derive n'agrandit l'original : cela n'ajoute pas d'information et
     * fait telecharger plus d'octets pour un resultat plus flou. Une image plus
     * petite que la plus petite largeur garde malgre tout un derive, sans quoi
     * elle n'aurait aucune source.
     *
     * @return list<int>
     */
    public function availableWidths(): array
    {
        $widths = array_values(array_filter(
            self::WIDTHS,
            fn (int $width): bool => $width <= $this->width,
        ));

        return $widths === [] ? [self::WIDTHS[0]] : $widths;
    }

    /**
     * Largeur du « src » de repli : celle que recoit un navigateur qui ne
     * comprend ni srcset ni sizes. 1024 px est le compromis — lisible sur un
     * ecran ordinaire sans imposer le fichier de 2400.
     */
    public function defaultWidth(): int
    {
        $widths = $this->availableWidths();
        $preferred = 1024;

        foreach (array_reverse($widths) as $width) {
            if ($width <= $preferred) {
                return $width;
            }
        }

        return $widths[0];
    }

    /**
     * Attribut srcset pour un format donne.
     */
    public function srcset(string $format): string
    {
        $sources = array_map(
            fn (int $width): string => $this->derivativeFilename($width, $format) . ' ' . $width . 'w',
            $this->availableWidths(),
        );

        return implode(', ', $sources);
    }

    /**
     * Valeur de aspect-ratio, pour reserver la place avant chargement.
     */
    public function aspectRatio(): string
    {
        return $this->width . ' / ' . $this->height;
    }

    public function alt(Locale $locale): string
    {
        return $this->translations->for($locale)->alt;
    }

    public function caption(Locale $locale): ?string
    {
        return $this->translations->for($locale)->caption;
    }

    /**
     * Valeur de object-position : le point d'interet reste visible quand
     * l'image est recadree en vignette. Centre par defaut.
     */
    public function objectPosition(): string
    {
        return ($this->focalX ?? 50) . '% ' . ($this->focalY ?? 50) . '%';
    }
}
