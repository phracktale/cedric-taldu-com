<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Admin\Role;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Persistance des comptes d'administration et de leurs codes de secours.
 *
 * Le point le plus sensible du fichier est l'unicite d'usage d'un code de
 * secours : elle ne peut PAS reposer sur un « lire puis ecrire » applicatif —
 * deux requetes simultanees liraient toutes deux un code inutilise. Elle repose
 * sur un UPDATE conditionnel et sur le nombre de lignes affectees, verifie ici
 * par deux consommations successives.
 */
final class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = new UserRepository($this->pdo);
    }

    // ------------------------------------------------------------- lecture

    public function test_un_compte_se_relit_par_son_adresse(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->named('Cédric Taldu')->create();

        $compte = $this->depot->findByEmail('artiste@example.test');

        $this->assertNotNull($compte);
        $this->assertSame('artiste@example.test', $compte->email);
        $this->assertSame('Cédric Taldu', $compte->displayName);
        $this->assertSame(Role::Admin, $compte->role);
        $this->assertFalse($compte->hasTwoFactor());
    }

    public function test_une_adresse_inconnue_ne_rend_rien(): void
    {
        $this->assertNull($this->depot->findByEmail('inconnu@example.test'));
    }

    public function test_l_adresse_est_comparee_sans_tenir_compte_de_la_casse(): void
    {
        // La collation utf8mb4_unicode_ci est insensible a la casse : « Artiste@ »
        // et « artiste@ » designent le meme compte. Le test le CONSTATE, pour
        // qu'un changement de collation ne cree pas deux comptes en silence.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $this->assertNotNull($this->depot->findByEmail('Artiste@Example.Test'));
    }

    public function test_un_compte_se_relit_par_son_identifiant(): void
    {
        $id = (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $compte = $this->depot->findById($id);

        $this->assertNotNull($compte);
        $this->assertSame($id, $compte->id);
    }

    public function test_un_identifiant_inconnu_ne_rend_rien(): void
    {
        $this->assertNull($this->depot->findById(999999));
    }

    public function test_un_editeur_relit_son_role(): void
    {
        (new UserFactory($this->pdo))->withEmail('editeur@example.test')->asEditor()->create();

        $compte = $this->depot->findByEmail('editeur@example.test');

        $this->assertNotNull($compte);
        $this->assertSame(Role::Editor, $compte->role);
        $this->assertFalse($compte->can('settings'));
    }

    public function test_un_compte_verrouille_relit_son_verrou(): void
    {
        (new UserFactory($this->pdo))
            ->withEmail('artiste@example.test')
            ->locked(5, '2026-07-21 09:45:00')
            ->create();

        $compte = $this->depot->findByEmail('artiste@example.test');

        $this->assertNotNull($compte);
        $this->assertSame(5, $compte->failedAttempts);
        $this->assertTrue($compte->isLocked($this->instant('2026-07-21 09:30:00')));
        $this->assertFalse($compte->isLocked($this->instant('2026-07-21 09:45:00')));
    }

    // ------------------------------------------------------------ ecriture

    public function test_un_echec_de_connexion_est_persiste(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $compte = $this->depot->findByEmail('artiste@example.test');
        $this->assertNotNull($compte);

        $this->depot->save($compte->withFailure($this->instant('2026-07-21 09:30:00')));

        $relu = $this->depot->findByEmail('artiste@example.test');
        $this->assertNotNull($relu);
        $this->assertSame(1, $relu->failedAttempts);
        $this->assertNull($relu->lockedUntil);
    }

    public function test_le_verrouillage_survit_a_un_redemarrage(): void
    {
        // Un verrou qui ne vivrait qu'en session serait leve par la suppression
        // d'un cookie : c'est la base qui le porte.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->locked(4, null)->create();
        $compte = $this->depot->findByEmail('artiste@example.test');
        $this->assertNotNull($compte);

        $this->depot->save($compte->withFailure($this->instant('2026-07-21 09:30:00')));

        $relu = $this->depot->findByEmail('artiste@example.test');
        $this->assertNotNull($relu);
        $this->assertSame(5, $relu->failedAttempts);
        $this->assertEquals($this->instant('2026-07-21 09:45:00'), $relu->lockedUntil);
    }

    public function test_une_connexion_reussie_efface_le_verrou_et_date_la_visite(): void
    {
        (new UserFactory($this->pdo))
            ->withEmail('artiste@example.test')
            ->locked(5, '2026-07-21 09:45:00')
            ->create();
        $compte = $this->depot->findByEmail('artiste@example.test');
        $this->assertNotNull($compte);

        $this->depot->save($compte->withSuccess($this->instant('2026-07-21 10:00:00')));

        $relu = $this->depot->findByEmail('artiste@example.test');
        $this->assertNotNull($relu);
        $this->assertSame(0, $relu->failedAttempts);
        $this->assertNull($relu->lockedUntil);
        $this->assertEquals($this->instant('2026-07-21 10:00:00'), $relu->lastLoginAt);
    }

    public function test_une_empreinte_reencodee_remplace_l_ancienne(): void
    {
        // 04-back-office §1 : reencodage a la connexion si les parametres du
        // hachage ont change.
        $id = (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $this->depot->updatePasswordHash($id, '$argon2id$nouvelle-empreinte');

        $compte = $this->depot->findById($id);
        $this->assertNotNull($compte);
        $this->assertSame('$argon2id$nouvelle-empreinte', $compte->passwordHash);
    }

    public function test_le_secret_totp_s_active_et_se_desactive(): void
    {
        $id = (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $this->depot->updateTotpSecret($id, 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $actif = $this->depot->findById($id);

        $this->depot->updateTotpSecret($id, null);
        $inactif = $this->depot->findById($id);

        $this->assertNotNull($actif);
        $this->assertTrue($actif->hasTwoFactor());
        $this->assertNotNull($inactif);
        $this->assertFalse($inactif->hasTwoFactor());
    }

    // ---------------------------------------------------------- creation

    public function test_un_compte_se_cree_et_se_compte(): void
    {
        // bin/create-admin.php s'en sert : sans compte, personne n'entre.
        $this->assertSame(0, $this->depot->countAll());

        $id = $this->depot->create(
            'artiste@example.test',
            '$argon2id$empreinte',
            'Cédric Taldu',
            Role::Admin,
            $this->instant('2026-07-21 09:30:00'),
        );

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->depot->countAll());
        $this->assertNotNull($this->depot->findByEmail('artiste@example.test'));
    }

    // ----------------------------------------------------- codes de secours

    public function test_les_codes_de_secours_remplacent_les_precedents(): void
    {
        // Regenerer les codes invalide les anciens : sinon une feuille perdue
        // resterait valable indefiniment.
        $id = (new UserFactory($this->pdo))->create();

        $this->depot->replaceBackupCodes($id, ['a', 'b', 'c'], $this->instant('2026-07-21 09:30:00'));
        $this->depot->replaceBackupCodes($id, ['d', 'e'], $this->instant('2026-07-21 10:00:00'));

        $this->assertSame(2, $this->depot->countUnusedBackupCodes($id));
        $this->assertFalse($this->depot->consumeBackupCode($id, 'a', $this->instant('2026-07-21 10:05:00')));
    }

    public function test_un_code_de_secours_ne_sert_qu_une_fois(): void
    {
        $id = (new UserFactory($this->pdo))->create();
        $this->depot->replaceBackupCodes($id, ['a', 'b'], $this->instant('2026-07-21 09:30:00'));

        $premier = $this->depot->consumeBackupCode($id, 'a', $this->instant('2026-07-21 09:31:00'));
        $second = $this->depot->consumeBackupCode($id, 'a', $this->instant('2026-07-21 09:32:00'));

        $this->assertTrue($premier);
        $this->assertFalse($second, 'Un code consommé ne peut pas resservir.');
        $this->assertSame(1, $this->depot->countUnusedBackupCodes($id));
    }

    public function test_un_code_inconnu_est_refuse(): void
    {
        $id = (new UserFactory($this->pdo))->create();
        $this->depot->replaceBackupCodes($id, ['a'], $this->instant('2026-07-21 09:30:00'));

        $this->assertFalse($this->depot->consumeBackupCode($id, 'z', $this->instant('2026-07-21 09:31:00')));
        $this->assertSame(1, $this->depot->countUnusedBackupCodes($id));
    }

    public function test_le_code_d_un_autre_compte_n_ouvre_pas_le_sien(): void
    {
        // Les empreintes sont poivrees mais pas liees au compte : sans le
        // filtre sur user_id, un code fuite ouvrirait n'importe quel compte.
        $artiste = (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $editeur = (new UserFactory($this->pdo))->withEmail('editeur@example.test')->create();

        $this->depot->replaceBackupCodes($artiste, ['a'], $this->instant('2026-07-21 09:30:00'));

        $this->assertFalse($this->depot->consumeBackupCode($editeur, 'a', $this->instant('2026-07-21 09:31:00')));
    }

    public function test_un_compte_sans_code_de_secours_en_compte_zero(): void
    {
        $id = (new UserFactory($this->pdo))->create();

        $this->assertSame(0, $this->depot->countUnusedBackupCodes($id));
    }

    private function instant(string $valeur): DateTimeImmutable
    {
        return new DateTimeImmutable($valeur, new DateTimeZone('UTC'));
    }
}
