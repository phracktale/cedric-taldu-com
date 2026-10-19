<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/**
 * Règle d'un nouveau mot de passe d'administration (demande du 2026-10-19).
 *
 * Douze caractères au moins, comme `bin/create-admin.php`. Deux cents au plus :
 * c'est la longueur que le formulaire de connexion accepte, un mot de passe
 * plus long serait enregistré mais ne permettrait plus de se connecter.
 * La longueur se compte en caractères, pas en octets.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 200;

    /** Message à afficher, ou null si le mot de passe convient. */
    public static function problem(string $password, string $confirmation): ?string
    {
        $length = mb_strlen($password, 'UTF-8');

        return match (true) {
            $length < self::MIN_LENGTH => sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_LENGTH),
            $length > self::MAX_LENGTH => sprintf('Le mot de passe ne peut pas dépasser %d caractères.', self::MAX_LENGTH),
            !hash_equals($password, $confirmation) => 'Les deux saisies du nouveau mot de passe ne sont pas identiques.',
            default => null,
        };
    }
}
