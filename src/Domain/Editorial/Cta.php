<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

/**
 * Bouton d'appel à l'action paramétrable (revue du 2026-09-24).
 *
 * Remplace les boutons écrits en dur dans les gabarits. Le libellé est traduit
 * et vit dans la partie par langue d'un réglage ; la cible, le style et
 * l'alignement sont communs aux langues ({@see toArray()}).
 *
 * Toute valeur hors liste retombe sur le défaut : ces valeurs finissent dans
 * des attributs HTML et des URL, seule une liste fermée peut y parvenir. Une
 * URL libre n'est acceptée que si elle est un chemin interne ou du https.
 */
final class Cta
{
    /** Cibles proposées ; « category » et « url » portent une précision. */
    public const TARGETS = [
        'galleries' => 'Galeries',
        'works' => 'Toutes les œuvres',
        'home' => 'Accueil',
        'about' => 'À propos',
        'booklet' => 'Livret',
        'news' => 'Actus',
        'contact' => 'Contact',
        'category' => 'Une galerie précise',
        'url' => 'Adresse libre',
    ];

    public const STYLES = ['plein' => 'Plein', 'vide' => 'Contour'];

    /** Réglage du bouton qui clôt chaque actualité (écran Apparence). */
    public const END_OF_POST_SETTING = 'blog.cta';

    public const ALIGNS = ['centre' => 'Centré', 'gauche' => 'À gauche', 'droite' => 'À droite'];

    private function __construct(
        public readonly string $label,
        public readonly string $target,
        public readonly ?int $categoryId,
        public readonly ?string $url,
        public readonly string $style,
        public readonly string $align,
    ) {
    }

    /**
     * @param array<string, mixed> $common réglages communs aux langues
     */
    public static function fromStored(array $common, ?string $label): ?self
    {
        $label = trim((string) $label);

        if ($label === '') {
            return null;
        }

        $target = self::pick($common['target'] ?? null, self::TARGETS);
        $categoryId = null;
        $url = null;

        if ($target === 'category') {
            $categoryId = self::positiveInt($common['category_id'] ?? null);
            $target = $categoryId === null ? 'galleries' : $target;
        }

        if ($target === 'url') {
            $url = self::safeUrl($common['url'] ?? null);
            $target = $url === null ? 'galleries' : $target;
        }

        return new self(
            $label,
            $target,
            $categoryId,
            $url,
            self::pick($common['style'] ?? null, self::STYLES),
            self::pick($common['align'] ?? null, self::ALIGNS),
        );
    }

    /**
     * Réglages communs, sans le libellé.
     *
     * @return array{target: string, category_id: int|null, url: string|null, style: string, align: string}
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'category_id' => $this->categoryId,
            'url' => $this->url,
            'style' => $this->style,
            'align' => $this->align,
        ];
    }

    /**
     * @param array<string, string> $allowed le premier est le défaut
     */
    private static function pick(mixed $value, array $allowed): string
    {
        return is_string($value) && array_key_exists($value, $allowed) ? $value : (string) array_key_first($allowed);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function safeUrl(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            return null;
        }

        $interne = str_starts_with($value, '/') && !str_starts_with($value, '//');
        $https = preg_match('~^https://[^/\s]+~i', $value) === 1;

        return $interne || $https ? $value : null;
    }
}
