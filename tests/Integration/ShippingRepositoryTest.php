<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Money;
use App\Domain\Shipping\ShippingMethod;
use App\Repository\ShippingRepository;
use Tests\Support\DatabaseTestCase;

/**
 * Chargement de la grille de port depuis la base.
 *
 * ShippingCalculator ne connait aucune zone ni aucun tarif : tout entre ici.
 * Changer la grille doit rester une insertion de lignes, jamais un
 * developpement (decision du 2026-07-21).
 */
final class ShippingRepositoryTest extends DatabaseTestCase
{
    private ShippingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ShippingRepository($this->pdo);
    }

    public function test_les_trois_zones_amorcees_sont_chargees(): void
    {
        $zones = $this->repository->zones();

        $this->assertSame('FR', $zones->zoneFor('FR')?->code);
        $this->assertSame('EU', $zones->zoneFor('DE')?->code);
        $this->assertSame('WORLD', $zones->zoneFor('JP')?->code);
    }

    public function test_les_tarifs_de_chaque_zone_sont_charges(): void
    {
        $calculateur = $this->repository->calculator();

        $this->assertSame(900, $calculateur->quote(
            ShippingMethod::Shipping,
            'FR',
            500,
            Money::fromCents(10000),
        )->price?->cents);

        $this->assertSame(2000, $calculateur->quote(
            ShippingMethod::Shipping,
            'DE',
            500,
            Money::fromCents(10000),
        )->price?->cents);

        $this->assertSame(3500, $calculateur->quote(
            ShippingMethod::Shipping,
            'JP',
            500,
            Money::fromCents(10000),
        )->price?->cents);
    }

    public function test_le_franco_de_chaque_zone_est_charge(): void
    {
        $calculateur = $this->repository->calculator();

        $this->assertSame(0, $calculateur->quote(
            ShippingMethod::Shipping,
            'FR',
            500,
            Money::fromCents(30000),
        )->price?->cents);

        // Aucun franco hors UE, quel que soit le montant.
        $this->assertSame(3500, $calculateur->quote(
            ShippingMethod::Shipping,
            'JP',
            500,
            Money::fromCents(1000000),
        )->price?->cents);
    }

    public function test_l_emballage_forfaitaire_vient_du_reglage(): void
    {
        // 9 750 g + 250 g d'emballage = 10 000 g pile : encore dans la tranche.
        $calculateur = $this->repository->calculator();

        $this->assertFalse(
            $calculateur->quote(ShippingMethod::Shipping, 'FR', 9750, Money::fromCents(10000))->isOnRequest(),
        );
        $this->assertTrue(
            $calculateur->quote(ShippingMethod::Shipping, 'FR', 9751, Money::fromCents(10000))->isOnRequest(),
        );
    }

    public function test_un_emballage_modifie_en_reglage_change_le_calcul(): void
    {
        $this->pdo->exec(
            "UPDATE settings SET value = '{\"packaging_grams\":1000}' WHERE `key` = 'shipping'"
        );

        $calculateur = $this->repository->calculator();

        $this->assertTrue(
            $calculateur->quote(ShippingMethod::Shipping, 'FR', 9500, Money::fromCents(10000))->isOnRequest(),
        );
    }

    public function test_un_reglage_d_emballage_absent_retombe_sur_deux_cent_cinquante(): void
    {
        $this->pdo->exec("DELETE FROM settings WHERE `key` = 'shipping'");

        $calculateur = $this->repository->calculator();

        $this->assertFalse(
            $calculateur->quote(ShippingMethod::Shipping, 'FR', 9750, Money::fromCents(10000))->isOnRequest(),
        );
        $this->assertTrue(
            $calculateur->quote(ShippingMethod::Shipping, 'FR', 9751, Money::fromCents(10000))->isOnRequest(),
        );
    }

    public function test_un_emballage_aberrant_retombe_sur_le_defaut(): void
    {
        // Un poids negatif reduirait le poids facture ; un poids absurde
        // enverrait tout en devis. Ni l'un ni l'autre ne doit passer.
        foreach (['-500', '"beaucoup"', 'null'] as $valeur) {
            $this->pdo->exec(
                "UPDATE settings SET value = '{\"packaging_grams\":{$valeur}}' WHERE `key` = 'shipping'"
            );

            $calculateur = $this->repository->calculator();

            $this->assertFalse(
                $calculateur->quote(ShippingMethod::Shipping, 'FR', 9750, Money::fromCents(10000))->isOnRequest(),
                "Reglage aberrant : {$valeur}",
            );
        }
    }

    public function test_une_tranche_ajoutee_est_prise_en_compte_sans_toucher_au_code(): void
    {
        // Le passage a une grille fine doit rester une insertion de lignes.
        $this->pdo->exec(
            "INSERT INTO shipping_rates (zone_id, max_weight_grams, price_cents, free_above_cents)
             SELECT id, 1000, 650, 30000 FROM shipping_zones WHERE code = 'FR'"
        );

        $calculateur = $this->repository->calculator();

        // 500 g + 250 g = 750 g : la nouvelle tranche a 1 000 g couvre.
        $this->assertSame(650, $calculateur->quote(
            ShippingMethod::Shipping,
            'FR',
            500,
            Money::fromCents(10000),
        )->price?->cents);

        // 2 000 g + 250 g : elle ne couvre plus, la tranche a 10 kg reprend.
        $this->assertSame(900, $calculateur->quote(
            ShippingMethod::Shipping,
            'FR',
            2000,
            Money::fromCents(10000),
        )->price?->cents);
    }

    public function test_une_zone_sans_pays_lisible_est_ignoree(): void
    {
        // shipping_zones.countries est du JSON saisi en back-office. La colonne
        // etant de type JSON, MySQL refuse lui-meme le texte invalide : la
        // seule corruption possible est un JSON VALIDE de mauvaise forme.
        foreach (['"FR"', '[]', '{}', '[1, 2, 3]', 'null'] as $forme) {
            $statement = $this->pdo->prepare(
                "UPDATE shipping_zones SET countries = :countries WHERE code = 'FR'"
            );
            $statement->execute(['countries' => $forme]);

            // La France retombe dans la zone Monde plutot que de planter.
            $this->assertSame(
                'WORLD',
                $this->repository->zones()->zoneFor('FR')?->code,
                "Forme acceptee a tort : {$forme}",
            );
        }
    }

    public function test_un_texte_non_json_est_refuse_par_la_colonne_elle_meme(): void
    {
        // Ceinture et bretelles : le type de colonne ferme le cas avant meme
        // que le depot n'ait a s'en occuper.
        $this->expectException(\PDOException::class);

        $this->pdo->exec("UPDATE shipping_zones SET countries = 'pas du json' WHERE code = 'FR'");
    }

    public function test_une_grille_vide_envoie_tout_en_devis(): void
    {
        $this->pdo->exec('DELETE FROM shipping_rates');

        $this->assertTrue(
            $this->repository->calculator()
                ->quote(ShippingMethod::Shipping, 'FR', 500, Money::fromCents(10000))
                ->isOnRequest(),
        );
    }

    public function test_la_remise_en_main_propre_reste_gratuite_sans_aucune_zone(): void
    {
        // Le seul mode d'achat qui doit survivre a une base de port vide.
        $this->pdo->exec('DELETE FROM shipping_rates');
        $this->pdo->exec('DELETE FROM shipping_zones');

        $devis = $this->repository->calculator()
            ->quote(ShippingMethod::Pickup, 'FR', 500, Money::fromCents(10000));

        $this->assertFalse($devis->isOnRequest());
        $this->assertSame(0, $devis->price?->cents);
    }
}
