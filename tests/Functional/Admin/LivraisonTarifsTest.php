<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Boutique › Livraisons (retours du 2026-09-25) : module de livraison choisi,
 * grille de tarifs par zone et par tranche de poids, emballage forfaitaire.
 */
final class LivraisonTarifsTest extends AdminTestCase
{
    private const ECRAN = '/cedric-taldu/admin/livraison';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_montre_la_grille_le_module_et_l_emballage(): void
    {
        $corps = $this->requete('GET', self::ECRAN)->body;
        $france = $this->zone('FR');

        $this->assertStringContainsString('action="' . self::ECRAN . '/tarifs"', $corps);
        $this->assertStringContainsString('name="z' . $france . '_fr" value="France"', $corps);
        $this->assertStringContainsString('name="z' . $france . '_t0_poids" value="10"', $corps);
        $this->assertStringContainsString('name="z' . $france . '_t0_prix" value="9,00"', $corps);
        $this->assertStringContainsString('name="z' . $france . '_t0_franco" value="300,00"', $corps);
        $this->assertStringContainsString('name="emballage" value="250"', $corps);
        $this->assertStringContainsString('<option value="colissimo" selected>Colissimo</option>', $corps);
        $this->assertStringContainsString('name="nz_fr"', $corps);
    }

    public function test_enregistrer_une_grille_fine(): void
    {
        $france = $this->zone('FR');
        $monde = $this->zone('WORLD');

        $reponse = $this->postAvecJeton(self::ECRAN . '/tarifs', [
            ...$this->zoneInchangee('EU'),
            "z{$france}_fr" => 'France métropolitaine', "z{$france}_en" => 'France', "z{$france}_pays" => 'FR',
            "z{$france}_t0_poids" => '0,5', "z{$france}_t0_prix" => '5,50', "z{$france}_t0_franco" => '',
            "z{$france}_t1_poids" => '10', "z{$france}_t1_prix" => '9', "z{$france}_t1_franco" => '300',
            "z{$monde}_fr" => 'Monde', "z{$monde}_pays" => '*', "z{$monde}_suppr" => '1',
            'nz_fr' => 'Suisse', 'nz_en' => 'Switzerland', 'nz_pays' => 'CH',
            'nz_t0_poids' => '10', 'nz_t0_prix' => '25', 'nz_t0_franco' => '',
            'emballage' => '400',
            'transporteur' => 'colissimo',
        ]);

        $this->assertSame(303, $reponse->status);
        $this->assertSame(
            [['500', '550', null], ['10000', '900', '30000']],
            $this->tranches($france),
        );
        $this->assertSame('France métropolitaine', $this->pdo->query("SELECT label_fr FROM shipping_zones WHERE id = {$france}")->fetchColumn());
        $this->assertSame('0', (string) $this->pdo->query("SELECT COUNT(*) FROM shipping_zones WHERE id = {$monde}")->fetchColumn());
        $suisse = (int) $this->pdo->query("SELECT id FROM shipping_zones WHERE label_fr = 'Suisse'")->fetchColumn();
        $this->assertSame('["CH"]', $this->pdo->query("SELECT countries FROM shipping_zones WHERE id = {$suisse}")->fetchColumn());
        $this->assertSame([['10000', '2500', null]], $this->tranches($suisse));

        $reglage = json_decode((string) $this->pdo->query("SELECT value FROM settings WHERE `key` = 'shipping'")->fetchColumn(), true);
        $this->assertSame(400, $reglage['packaging_grams']);
        $this->assertSame('colissimo', $reglage['carrier']);
    }

    public function test_une_saisie_invalide_ne_change_rien_et_dit_pourquoi(): void
    {
        $france = $this->zone('FR');

        $reponse = $this->postAvecJeton(self::ECRAN . '/tarifs', [
            "z{$france}_fr" => 'France', "z{$france}_pays" => 'FRA',
            "z{$france}_t0_poids" => '10', "z{$france}_t0_prix" => '9', "z{$france}_t0_franco" => '',
            'emballage' => '250',
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('pays « FRA » inconnu', $reponse->body);
        $this->assertSame([['10000', '900', '30000']], $this->tranches($france));
    }

    private function zone(string $code): int
    {
        return (int) $this->pdo->query("SELECT id FROM shipping_zones WHERE code = '{$code}'")->fetchColumn();
    }

    /**
     * @return array<string, string>
     */
    private function zoneInchangee(string $code): array
    {
        $id = $this->zone($code);

        return ["z{$id}_fr" => 'Union européenne', "z{$id}_en" => 'European Union', "z{$id}_pays" => 'DE, FR',
            "z{$id}_t0_poids" => '10', "z{$id}_t0_prix" => '20', "z{$id}_t0_franco" => '800'];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    private function tranches(int $zone): array
    {
        $lignes = $this->pdo->query(
            "SELECT max_weight_grams, price_cents, free_above_cents FROM shipping_rates WHERE zone_id = {$zone} ORDER BY max_weight_grams"
        )->fetchAll(\PDO::FETCH_NUM);

        return array_map(static fn (array $l): array => [(string) $l[0], (string) $l[1], $l[2] === null ? null : (string) $l[2]], $lignes);
    }
}
