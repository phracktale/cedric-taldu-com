<?php

declare(strict_types=1);

namespace APress\Core\Ui;

/**
 * Échappement pour les gabarits du cœur. Méthodes statiques et non fonctions
 * globales : un site (cedrictaldu) définit déjà ses propres e() et attr().
 * Toute valeur écrite dans un gabarit du cœur passe par ici.
 */
final class Html
{
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function attr(string|int|float|null $value): string
    {
        return self::e($value);
    }

    /**
     * Données passées au JavaScript par un attribut data-*.
     */
    public static function json(mixed $value): string
    {
        return htmlspecialchars(
            (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8',
        );
    }
}
