<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use App\Domain\Admin\AdminUser;
use App\Domain\Admin\Role;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * 04-back-office §1 : « 5 echecs -> verrouillage du compte 15 minutes
 * (`locked_until`) ».
 *
 * Le comptage et l'expiration du verrou sont une regle metier pure : ils se
 * testent sans base et sans attendre quinze minutes. Le depot ne fait que
 * persister le resultat.
 */
final class AdminUserTest extends TestCase
{
    private const MAINTENANT = '2026-07-21 09:30:00';

    public function test_un_compte_neuf_n_est_pas_verrouille(): void
    {
        $compte = $this->compte();

        $this->assertFalse($compte->isLocked($this->instant(self::MAINTENANT)));
        $this->assertSame(0, $compte->failedAttempts);
    }

    public function test_quatre_echecs_ne_verrouillent_pas_encore(): void
    {
        // La borne compte : verrouiller au quatrieme echec priverait l'artiste
        // d'un essai que la spec lui accorde.
        $compte = $this->apresEchecs(4);

        $this->assertSame(4, $compte->failedAttempts);
        $this->assertNull($compte->lockedUntil);
        $this->assertFalse($compte->isLocked($this->instant(self::MAINTENANT)));
    }

    public function test_le_cinquieme_echec_verrouille_le_compte_un_quart_d_heure(): void
    {
        $compte = $this->apresEchecs(5);

        $this->assertSame(5, $compte->failedAttempts);
        $this->assertEquals($this->instant('2026-07-21 09:45:00'), $compte->lockedUntil);
        $this->assertTrue($compte->isLocked($this->instant(self::MAINTENANT)));
    }

    public function test_le_verrou_tombe_a_l_echeance(): void
    {
        $compte = $this->apresEchecs(5);

        // A la seconde pres : le verrou expire, il ne s'attarde pas.
        $this->assertTrue($compte->isLocked($this->instant('2026-07-21 09:44:59')));
        $this->assertFalse($compte->isLocked($this->instant('2026-07-21 09:45:00')));
    }

    public function test_un_echec_apres_l_echeance_reverrouille_immediatement(): void
    {
        // Le compteur n'est PAS remis a zero par l'expiration du verrou : seule
        // une connexion reussie l'efface. Sans cela, un attaquant patient
        // disposerait de cinq essais tous les quarts d'heure indefiniment,
        // c'est-a-dire d'une temporisation et non d'un verrouillage.
        $compte = $this->apresEchecs(5)->withFailure($this->instant('2026-07-21 10:00:00'));

        $this->assertSame(6, $compte->failedAttempts);
        $this->assertEquals($this->instant('2026-07-21 10:15:00'), $compte->lockedUntil);
    }

    public function test_une_connexion_reussie_efface_le_compteur_et_le_verrou(): void
    {
        $compte = $this->apresEchecs(5)->withSuccess($this->instant('2026-07-21 10:00:00'));

        $this->assertSame(0, $compte->failedAttempts);
        $this->assertNull($compte->lockedUntil);
        $this->assertEquals($this->instant('2026-07-21 10:00:00'), $compte->lastLoginAt);
        $this->assertFalse($compte->isLocked($this->instant('2026-07-21 10:00:00')));
    }

    public function test_le_compte_reste_immuable(): void
    {
        // ARCHITECTURE §4 : entites immuables. Un `withFailure()` qui muterait
        // l'instance rendrait le comptage dependant de l'ordre des appels.
        $origine = $this->compte();

        $origine->withFailure($this->instant(self::MAINTENANT));

        $this->assertSame(0, $origine->failedAttempts);
    }

    // ------------------------------------------------------------------ 2FA

    public function test_un_compte_sans_secret_totp_n_a_pas_de_double_facteur(): void
    {
        $this->assertFalse($this->compte()->hasTwoFactor());
    }

    public function test_un_compte_avec_secret_totp_exige_le_double_facteur(): void
    {
        // 04-back-office §1 : la 2FA est optionnelle, mais des qu'un secret
        // existe elle devient obligatoire pour ce compte — sinon l'activer
        // n'apporterait rien.
        $this->assertTrue($this->compte(totpSecret: 'JBSWY3DPEHPK3PXP')->hasTwoFactor());
    }

    public function test_un_secret_vide_ne_vaut_pas_un_double_facteur(): void
    {
        // Une chaine vide en base est l'accident classique : elle ne doit pas
        // reclamer un code que l'artiste ne peut pas produire.
        $this->assertFalse($this->compte(totpSecret: '')->hasTwoFactor());
    }

    // ----------------------------------------------------------------- role

    public function test_le_compte_porte_les_droits_de_son_role(): void
    {
        $this->assertTrue($this->compte()->can('orders'));
        $this->assertFalse($this->compte(role: Role::Editor)->can('orders'));
        $this->assertTrue($this->compte(role: Role::Editor)->can('catalog'));
    }

    // --------------------------------------------------------------- outils

    private function compte(
        Role $role = Role::Admin,
        ?string $totpSecret = null,
    ): AdminUser {
        return new AdminUser(
            id: 1,
            email: 'artiste@example.test',
            passwordHash: '$argon2id$peu-importe',
            displayName: 'Cédric Taldu',
            role: $role,
            totpSecret: $totpSecret,
            failedAttempts: 0,
            lockedUntil: null,
            lastLoginAt: null,
        );
    }

    private function apresEchecs(int $nombre): AdminUser
    {
        $compte = $this->compte();

        for ($i = 0; $i < $nombre; $i++) {
            $compte = $compte->withFailure($this->instant(self::MAINTENANT));
        }

        return $compte;
    }

    private function instant(string $valeur): DateTimeImmutable
    {
        return new DateTimeImmutable($valeur, new DateTimeZone('UTC'));
    }
}
