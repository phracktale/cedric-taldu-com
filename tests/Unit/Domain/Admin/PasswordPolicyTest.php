<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use App\Domain\Admin\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Règle d'un nouveau mot de passe d'administration (demande du 2026-10-19).
 *
 * La même que `bin/create-admin.php` : douze caractères au moins. Au-delà de
 * deux cents, le formulaire de connexion le refuserait — le mot de passe
 * serait enregistré mais inutilisable.
 */
final class PasswordPolicyTest extends TestCase
{
    public function test_un_mot_de_passe_de_douze_caracteres_confirme_est_accepte(): void
    {
        $this->assertNull(PasswordPolicy::problem('douze-lettre', 'douze-lettre'));
    }

    public function test_moins_de_douze_caracteres_est_refuse(): void
    {
        $this->assertSame(
            'Le mot de passe doit faire au moins 12 caractères.',
            PasswordPolicy::problem('onze-lettre', 'onze-lettre'),
        );
    }

    public function test_la_longueur_se_compte_en_caracteres_et_non_en_octets(): void
    {
        // Six lettres accentuées font douze octets : ce n'est pas assez.
        $this->assertNotNull(PasswordPolicy::problem('éèêëàâ', 'éèêëàâ'));
    }

    public function test_plus_de_deux_cents_caracteres_est_refuse(): void
    {
        $long = str_repeat('a', 201);

        $this->assertSame(
            'Le mot de passe ne peut pas dépasser 200 caractères.',
            PasswordPolicy::problem($long, $long),
        );
    }

    public function test_une_confirmation_differente_est_refusee(): void
    {
        $this->assertSame(
            'Les deux saisies du nouveau mot de passe ne sont pas identiques.',
            PasswordPolicy::problem('atelier-encre-2026', 'atelier-encre-2025'),
        );
    }
}
