<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Écran « Livraison » (revue du 2026-09-24) : zone de remise en main propre et
 * état des transporteurs.
 */
final class LivraisonAdminTest extends AdminTestCase
{
    private const ADMIN = '/cedric-taldu/admin/livraison';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_montre_la_zone_et_l_etat_de_colissimo(): void
    {
        $reponse = $this->requete('GET', self::ADMIN);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('href="' . self::ADMIN . '"', $reponse->body);
        $this->assertStringContainsString('name="rayon"', $reponse->body);
        $this->assertStringContainsString('value="30"', $reponse->body);
        $this->assertStringContainsString('Colissimo', $reponse->body);
        $this->assertStringContainsString('identifiants absents', $reponse->body);
    }

    public function test_la_zone_se_regle_depuis_l_adresse_de_l_atelier(): void
    {
        $reponse = $this->postAvecJeton(self::ADMIN, [
            'lieu' => 'Dreuil-lès-Amiens', 'adresse' => '25 allée des Lilas', 'code_postal' => '80470', 'rayon' => '20',
        ]);

        $this->assertSame(302, $reponse->status);
        /** @var array{lat: float, lng: float, radius_km: int, place: string} $reglage */
        $reglage = json_decode((string) $this->valeur("SELECT value FROM settings WHERE `key` = 'shipping.hand_delivery'"), true);
        $this->assertSame(20, $reglage['radius_km']);
        $this->assertSame('Dreuil-lès-Amiens', $reglage['place']);
        $this->assertEqualsWithDelta(49.9147, $reglage['lat'], 0.001);
    }

    public function test_une_adresse_introuvable_est_refusee_sans_rien_changer(): void
    {
        $this->geocodeur->muet();

        $reponse = $this->postAvecJeton(self::ADMIN, ['lieu' => 'Nulle part', 'adresse' => 'x', 'code_postal' => '00000', 'rayon' => '20']);

        $this->assertSame(422, $reponse->status);
        $this->assertNull($this->valeur("SELECT value FROM settings WHERE `key` = 'shipping.hand_delivery'"));
    }

    public function test_l_enregistrement_sans_jeton_csrf_est_refuse(): void
    {
        $this->assertContains($this->requete('POST', self::ADMIN, post: ['rayon' => '20'])->status, [403, 419]);
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false || $valeur === null ? null : (string) $valeur;
    }
}
