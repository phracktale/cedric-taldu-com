<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\InvalidSlug;

/**
 * Identifiant d'URL d'un contenu.
 *
 * Le format est exactement celui de Core\Route::SLUG : minuscules, chiffres et
 * tirets simples. C'est ce qui garantit qu'un slug produit ici sera accepte par
 * UrlGenerator, et qu'un slug recu dans une URL ne peut porter aucune charge.
 *
 * Les slugs sont propres a chaque langue (artwork_translations.slug) : une
 * meme œuvre a « articulation » en francais et un autre slug en anglais.
 */
final class Slug
{
    /** Longueur maximale : artwork_translations.slug est un VARCHAR(190). */
    private const MAX_LENGTH = 190;

    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /**
     * Ligatures et lettres accentuees que la translitteration doit rendre
     * lisibles. « Œuvre » doit devenir « oeuvre » et non « uvre ».
     */
    private const TRANSLITERATIONS = [
        'œ' => 'oe', 'Œ' => 'oe', 'æ' => 'ae', 'Æ' => 'ae',
        'ß' => 'ss', 'ø' => 'o', 'Ø' => 'o', 'đ' => 'd', 'Đ' => 'd',
        'ł' => 'l', 'Ł' => 'l', 'þ' => 'th', 'Þ' => 'th', 'ð' => 'd', 'Ð' => 'd',
    ];

    private function __construct(public readonly string $value)
    {
    }

    /**
     * Produit un slug a partir d'un titre lisible.
     */
    public static function fromTitle(string $title): self
    {
        $value = strtr($title, self::TRANSLITERATIONS);

        // Decompose les caracteres accentues en lettre + diacritique, puis
        // retire les diacritiques : « é » devient « e ».
        $decomposed = normalizer_normalize($value, \Normalizer::FORM_D);
        if (is_string($decomposed)) {
            $value = $decomposed;
        }

        $value = (string) preg_replace('/\p{Mn}+/u', '', $value);

        // Tout ce qui n'est pas lettre ASCII ou chiffre devient un separateur.
        // Les ideogrammes et les emoji disparaissent donc : c'est voulu, et
        // c'est ce qui declenche le refus plus bas.
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');

        if ($value === '') {
            throw InvalidSlug::forTitle($title);
        }

        return new self(self::truncate($value));
    }

    /**
     * Reprend une valeur deja au format, sans la transformer. Employe pour les
     * slugs saisis a la main en back-office et pour ceux relus en base.
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1 || strlen($value) > self::MAX_LENGTH) {
            throw InvalidSlug::forValue($value);
        }

        return new self($value);
    }

    /**
     * Variante numerotee, pour lever une collision d'unicite.
     *
     * Le suffixe ne s'empile pas : deux collisions successives donnent
     * « articulation-2 » puis « articulation-3 », jamais « articulation-2-3 ».
     */
    public function withSuffix(int $suffix): self
    {
        if ($suffix < 2) {
            throw InvalidSlug::forSuffix($suffix);
        }

        $base = (string) preg_replace('/-[0-9]+$/', '', $this->value);
        $marker = '-' . $suffix;

        return new self(self::truncate($base, strlen($marker)) . $marker);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Tronque sur une frontiere de tiret, pour ne pas couper au milieu d'un mot.
     */
    private static function truncate(string $value, int $reserved = 0): string
    {
        $limit = self::MAX_LENGTH - $reserved;

        if (strlen($value) <= $limit) {
            return $value;
        }

        $cut = substr($value, 0, $limit);
        $lastDash = strrpos($cut, '-');

        if ($lastDash !== false && $lastDash > 0) {
            $cut = substr($cut, 0, $lastDash);
        }

        return rtrim($cut, '-');
    }
}
