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

    /** Plus grand dérivé ; au-delà, seul un plein format JPEG sert au zoom. */
    public const MAX_WIDTH = 2400;

    /**
     * Formats, DU PLUS EFFICACE AU REPLI. L'ordre compte : <picture> retient la
     * premiere source que le navigateur comprend.
     *
     * AVIF est absent volontairement : la GD du conteneur php:8.2-apache n'a pas
     * le support AVIF, et annoncer une source qu'aucun fichier n'accompagne
     * afficherait une image cassee chez tout navigateur qui comprend le format.
     * Il rejoindra cette liste au lot 2, avec le pipeline d'images et libavif
     * dans l'image Docker.
     */
    public const FORMATS = ['webp', 'jpg'];

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
    /**
     * Toutes les largeurs de dérivés PRODUITES pour cette image (retours du
     * 2026-09-28) : les largeurs génériques (availableWidths), les largeurs
     * exactes des points de rupture de la fiche (ImageBreakpoints) qui ne
     * dépassent pas l'original, et la largeur native jusqu'à 2400 px — pour
     * qu'un point de rupture plus large que l'original l'affiche à sa densité
     * réelle plutôt qu'agrandi. Source unique de vérité : l'ImageProcessor
     * produit exactement ces fichiers, le gabarit ne cite qu'eux.
     *
     * @return list<int>
     */
    public function derivativeWidths(): array
    {
        return self::derivativeWidthsFor($this->width);
    }

    /**
     * Même règle, depuis la seule largeur de l'original (ImageProcessor).
     *
     * @return list<int>
     */
    public static function derivativeWidthsFor(int $width): array
    {
        $largeurs = array_values(array_filter(self::WIDTHS, static fn (int $l): bool => $l <= $width)) ?: [self::WIDTHS[0]];

        foreach (ImageBreakpoints::pixelWidths() as $largeur) {
            if ($largeur <= $width) {
                $largeurs[] = $largeur;
            }
        }

        if ($width <= self::MAX_WIDTH) {
            $largeurs[] = $width;
        }

        $largeurs = array_values(array_unique($largeurs));
        sort($largeurs);

        return $largeurs;
    }

    /**
     * Un plein format JPEG (`-full.jpg`) n'existe qu'au-delà du plus grand
     * dérivé : c'est lui qu'ouvre le zoom, pour que les points restent nets.
     */
    public function hasFullDerivative(): bool
    {
        return $this->width > self::MAX_WIDTH;
    }

    public function fullFilename(): string
    {
        return $this->publicBasename . '-full.jpg';
    }

    /**
     * Fichier ouvert par le zoom : l'image à sa résolution native.
     */
    public function zoomFilename(): string
    {
        return $this->hasFullDerivative()
            ? $this->fullFilename()
            : $this->derivativeFilename($this->width, 'jpg');
    }

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
    /**
     * Gabarit de la vignette (revue du 2026-09-24) : vertical, horizontal ou
     * carré, à 5 % près. Le cadre est fixe par orientation (CSS) et l'image y
     * tient entière, jamais rognée.
     */
    public function orientation(): string
    {
        if ($this->width <= 0 || $this->height <= 0 || abs($this->width - $this->height) <= 0.05 * max($this->width, $this->height)) {
            return 'carre';
        }

        return $this->height > $this->width ? 'portrait' : 'paysage';
    }

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
    /**
     * Point focal en classes `fx-NN fy-NN`, arrondi à 10 % : la CSP bloque
     * l'attribut style, les classes posent les variables de object-position.
     */
    public function focalClasses(): string
    {
        $arrondi = static fn (?int $valeur): int => (int) (round(max(0, min(100, $valeur ?? 50)) / 10) * 10);

        return 'fx-' . $arrondi($this->focalX) . ' fy-' . $arrondi($this->focalY);
    }

    public function objectPosition(): string
    {
        return ($this->focalX ?? 50) . '% ' . ($this->focalY ?? 50) . '%';
    }
}
