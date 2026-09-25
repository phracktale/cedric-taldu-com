<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shipping;

use App\Domain\Shipping\ShippingGridForm;
use PHPUnit\Framework\TestCase;

/**
 * Grille de port saisie en back-office (retours du 2026-09-25, Boutique ›
 * Livraisons) : poids en kilos, prix en euros, pays en codes ISO à deux
 * lettres (ou « * » pour le reste du monde). Aucun flottant pour l'argent.
 */
final class ShippingGridFormTest extends TestCase
{
    public function test_une_zone_et_ses_tranches_saisies_en_kilos_et_en_euros(): void
    {
        $grille = ShippingGridForm::parse([
            'z1_fr' => 'France', 'z1_en' => 'France', 'z1_pays' => 'fr',
            'z1_t0_poids' => '0,5', 'z1_t0_prix' => '5,50', 'z1_t0_franco' => '',
            'z1_t1_poids' => '2', 'z1_t1_prix' => '9', 'z1_t1_franco' => '300',
            'z1_t2_poids' => '', 'z1_t2_prix' => '', 'z1_t2_franco' => '',
        ], [1]);

        $this->assertSame([], $grille->errors);
        $this->assertSame([[
            'id' => 1,
            'fr' => 'France',
            'en' => 'France',
            'countries' => ['FR'],
            'delete' => false,
            'brackets' => [
                ['grams' => 500, 'cents' => 550, 'freeAboveCents' => null],
                ['grams' => 2000, 'cents' => 900, 'freeAboveCents' => 30000],
            ],
        ]], $grille->zones);
    }

    public function test_les_pays_se_saisissent_en_liste_ou_en_etoile(): void
    {
        $grille = ShippingGridForm::parse([
            'z1_fr' => 'Voisins', 'z1_en' => '', 'z1_pays' => 'de, be ; ch',
            'z2_fr' => 'Monde', 'z2_en' => 'World', 'z2_pays' => '*',
        ], [1, 2]);

        $this->assertSame(['DE', 'BE', 'CH'], $grille->zones[0]['countries']);
        // Libellé anglais vide : repli sur le français.
        $this->assertSame('Voisins', $grille->zones[0]['en']);
        $this->assertSame(['*'], $grille->zones[1]['countries']);
    }

    public function test_les_saisies_invalides_sont_signalees(): void
    {
        $grille = ShippingGridForm::parse([
            'z1_fr' => 'France', 'z1_pays' => 'FRA',
            'z1_t0_poids' => 'lourd', 'z1_t0_prix' => '9',
            'z1_t1_poids' => '2', 'z1_t1_prix' => '-3',
            'z1_t2_poids' => '2', 'z1_t2_prix' => '4',
            'z2_fr' => '', 'z2_pays' => 'FR',
        ], [1, 2]);

        $this->assertSame([
            'France : pays « FRA » inconnu (codes à deux lettres, ou * pour le reste du monde).',
            'France : poids « lourd » illisible (en kilos, ex. 0,5).',
            'France : prix « -3 » illisible (en euros, ex. 9,50).',
            'France : deux tranches à 2 kg.',
            'Une zone sans nom : donnez-lui un nom.',
        ], $grille->errors);
    }

    public function test_une_zone_cochee_est_supprimee_sans_etre_validee(): void
    {
        $grille = ShippingGridForm::parse(['z3_fr' => '', 'z3_pays' => 'nimporte', 'z3_suppr' => '1'], [3]);

        $this->assertSame([], $grille->errors);
        $this->assertTrue($grille->zones[0]['delete']);
    }

    public function test_une_nouvelle_zone_n_est_creee_que_si_elle_a_un_nom(): void
    {
        $vide = ShippingGridForm::parse(['nz_fr' => '', 'nz_pays' => ''], []);
        $this->assertSame([], $vide->zones);

        $suisse = ShippingGridForm::parse([
            'nz_fr' => 'Suisse', 'nz_en' => 'Switzerland', 'nz_pays' => 'CH',
            'nz_t0_poids' => '10', 'nz_t0_prix' => '25', 'nz_t0_franco' => '',
        ], []);
        $this->assertNull($suisse->zones[0]['id']);
        $this->assertSame([['grams' => 10000, 'cents' => 2500, 'freeAboveCents' => null]], $suisse->zones[0]['brackets']);
    }
}
