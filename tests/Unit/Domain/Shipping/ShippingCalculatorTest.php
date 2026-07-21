<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shipping;

use App\Domain\Money;
use App\Domain\Shipping\ShippingCalculator;
use App\Domain\Shipping\ShippingMethod;
use App\Domain\Shipping\ShippingQuote;
use App\Domain\Shipping\ShippingZone;
use App\Domain\Shipping\ShippingZones;
use App\Domain\Shipping\WeightBracket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calcul des frais de port (03-boutique §4).
 *
 *   zone   = zone dont countries contient le pays de livraison, sinon WORLD
 *   poids  = Σ (poids unitaire × quantite) + emballage forfaitaire
 *   tarif  = premiere tranche de la zone dont max_weight_grams >= poids
 *   franco = si sous-total >= free_above_cents, port a 0
 *   remise en main propre -> port a 0
 *
 * La grille amorcee est celle tranchee le 2026-07-21 : forfait unique par
 * zone, une seule tranche a 10 kg.
 */
#[CoversClass(ShippingCalculator::class)]
#[CoversClass(ShippingZone::class)]
#[CoversClass(ShippingZones::class)]
#[CoversClass(WeightBracket::class)]
#[CoversClass(ShippingQuote::class)]
final class ShippingCalculatorTest extends TestCase
{
    private const EMBALLAGE = 250;

    // ------------------------------------------------------ choix de la zone

    #[DataProvider('paysEtZones')]
    public function test_le_pays_de_livraison_designe_la_zone(string $pays, string $zoneAttendue, int $portAttendu): void
    {
        $devis = $this->calculer(ShippingMethod::Shipping, $pays, 500, Money::fromCents(10000));

        $this->assertNotNull($devis->zone);
        $this->assertSame($zoneAttendue, $devis->zone->code);
        $this->assertSame($portAttendu, $this->centimes($devis));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function paysEtZones(): iterable
    {
        yield 'France' => ['FR', 'FR', 900];
        yield 'Allemagne' => ['DE', 'EU', 2000];
        yield 'Belgique' => ['BE', 'EU', 2000];
        yield 'Japon' => ['JP', 'WORLD', 3500];
        yield 'Etats-Unis' => ['US', 'WORLD', 3500];
    }

    public function test_le_code_pays_est_compare_sans_tenir_compte_de_la_casse(): void
    {
        // Un formulaire peut renvoyer « fr ». Comparer brutalement enverrait la
        // commande en zone Monde et la surfacturerait de 26 €.
        $devis = $this->calculer(ShippingMethod::Shipping, 'fr', 500, Money::fromCents(10000));

        $this->assertNotNull($devis->zone);
        $this->assertSame('FR', $devis->zone->code);
    }

    public function test_un_pays_inconnu_tombe_dans_la_zone_monde(): void
    {
        // 03-boutique §4 : « sinon WORLD ». La zone Monde porte ["*"].
        $devis = $this->calculer(ShippingMethod::Shipping, 'ZZ', 500, Money::fromCents(10000));

        $this->assertNotNull($devis->zone);
        $this->assertSame('WORLD', $devis->zone->code);
    }

    public function test_sans_zone_universelle_un_pays_inconnu_donne_un_devis_sur_demande(): void
    {
        // Si l'artiste retire la zone Monde de la base, une commande vers un
        // pays non couvert ne doit pas etre facturee au hasard.
        $calculateur = new ShippingCalculator(
            new ShippingZones(self::zoneFrance()),
            self::EMBALLAGE,
        );

        $devis = $calculateur->quote(ShippingMethod::Shipping, 'JP', 500, Money::fromCents(10000));

        $this->assertTrue($devis->isOnRequest());
    }

    // ---------------------------------------------------------------- poids

    public function test_l_emballage_forfaitaire_s_ajoute_au_poids_des_articles(): void
    {
        // 9 750 g d'articles + 250 g d'emballage = 10 000 g pile : la tranche
        // couvre encore. Un gramme de plus et elle ne couvre plus.
        $this->assertSame(900, $this->centimes(
            $this->calculer(ShippingMethod::Shipping, 'FR', 9750, Money::fromCents(10000)),
        ));

        $this->assertTrue(
            $this->calculer(ShippingMethod::Shipping, 'FR', 9751, Money::fromCents(10000))->isOnRequest(),
        );
    }

    public function test_un_poids_d_articles_nul_reste_facture(): void
    {
        // Le colis existe quand meme : il pese au moins son emballage.
        $this->assertSame(900, $this->centimes(
            $this->calculer(ShippingMethod::Shipping, 'FR', 0, Money::fromCents(10000)),
        ));
    }

    public function test_un_poids_hors_bareme_donne_un_devis_sur_demande(): void
    {
        // 03-boutique §4 : « Si aucune tranche ne couvre le poids, la commande
        // n'est pas bloquee : le site affiche "devis d'expedition sur demande"
        // et propose le formulaire de contact. »
        $devis = $this->calculer(ShippingMethod::Shipping, 'FR', 40000, Money::fromCents(10000));

        $this->assertTrue($devis->isOnRequest());
        $this->assertNull($devis->price);
    }

    public function test_un_poids_indetermine_donne_un_devis_sur_demande(): void
    {
        // artworks.weight_grams est NULLABLE (01-modele §3) : une œuvre saisie
        // sans poids ne doit pas etre expediee a un tarif invente. Le cas
        // arrivera : c'est un champ facultatif d'un formulaire d'atelier.
        $devis = $this->calculer(ShippingMethod::Shipping, 'FR', null, Money::fromCents(10000));

        $this->assertTrue($devis->isOnRequest());
    }

    public function test_la_premiere_tranche_couvrante_est_retenue(): void
    {
        // Le modele reste celui de 01-modele §5 : plusieurs tranches par zone.
        // Passer un jour a une grille fine doit etre une insertion de lignes,
        // pas un developpement — ce test le verrouille des maintenant.
        $calculateur = new ShippingCalculator(
            new ShippingZones(new ShippingZone(
                'FR',
                'France',
                'France',
                ['FR'],
                new WeightBracket(5000, Money::fromCents(1500), null),
                new WeightBracket(1000, Money::fromCents(850), null),
                new WeightBracket(2000, Money::fromCents(1050), null),
            )),
            self::EMBALLAGE,
        );

        // 900 g d'articles + 250 g = 1 150 g : la tranche a 1 000 g ne couvre
        // pas, celle a 2 000 g couvre. Les tranches sont donnees en desordre
        // pour prouver que le tri est fait ici et non attendu du SQL.
        $this->assertSame(1050, $this->centimes(
            $calculateur->quote(ShippingMethod::Shipping, 'FR', 900, Money::fromCents(10000)),
        ));

        $this->assertSame(850, $this->centimes(
            $calculateur->quote(ShippingMethod::Shipping, 'FR', 700, Money::fromCents(10000)),
        ));
    }

    public function test_une_zone_sans_aucune_tranche_donne_un_devis_sur_demande(): void
    {
        $calculateur = new ShippingCalculator(
            new ShippingZones(new ShippingZone('FR', 'France', 'France', ['FR'])),
            self::EMBALLAGE,
        );

        $this->assertTrue(
            $calculateur->quote(ShippingMethod::Shipping, 'FR', 500, Money::fromCents(10000))->isOnRequest(),
        );
    }

    // --------------------------------------------------------------- franco

    public function test_le_franco_atteint_annule_les_frais_de_port(): void
    {
        // Franco France a 300,00 €.
        $devis = $this->calculer(ShippingMethod::Shipping, 'FR', 500, Money::fromCents(30000));

        $this->assertFalse($devis->isOnRequest());
        $this->assertSame(0, $this->centimes($devis));
    }

    public function test_le_franco_non_atteint_laisse_les_frais_de_port(): void
    {
        // Un centime en dessous du seuil : le port reste du.
        $this->assertSame(900, $this->centimes(
            $this->calculer(ShippingMethod::Shipping, 'FR', 500, Money::fromCents(29999)),
        ));
    }

    public function test_chaque_zone_a_son_propre_seuil_de_franco(): void
    {
        // Franco UE a 800,00 €, atteint ici ; le meme sous-total ne suffirait
        // pas hors UE, ou aucun franco n'existe.
        $this->assertSame(0, $this->centimes(
            $this->calculer(ShippingMethod::Shipping, 'DE', 500, Money::fromCents(80000)),
        ));

        $this->assertSame(2000, $this->centimes(
            $this->calculer(ShippingMethod::Shipping, 'DE', 500, Money::fromCents(79999)),
        ));
    }

    public function test_une_zone_sans_franco_facture_toujours_le_port(): void
    {
        // Zone Monde : aucun franco, quel que soit le montant.
        $this->assertSame(3500, $this->centimes(
            $this->calculer(ShippingMethod::Shipping, 'JP', 500, Money::fromCents(1000000)),
        ));
    }

    public function test_le_franco_ne_sauve_pas_un_poids_hors_bareme(): void
    {
        // Un colis de 40 kg reste hors barème meme paye 5 000 € : l'artiste
        // doit chiffrer l'expedition, pas l'offrir sans le savoir.
        $this->assertTrue(
            $this->calculer(ShippingMethod::Shipping, 'FR', 40000, Money::fromCents(500000))->isOnRequest(),
        );
    }

    // ------------------------------------------------- remise en main propre

    public function test_la_remise_en_main_propre_est_gratuite(): void
    {
        // 03-boutique §4 : « remise en main propre -> port = 0, adresse de
        // livraison non requise ».
        $devis = $this->calculer(ShippingMethod::Pickup, 'FR', 500, Money::fromCents(10000));

        $this->assertFalse($devis->isOnRequest());
        $this->assertSame(0, $this->centimes($devis));
    }

    public function test_la_remise_en_main_propre_ignore_le_poids_hors_bareme(): void
    {
        // C'est le seul moyen d'acheter une piece de 30 kg en ligne : elle est
        // retiree a l'atelier d'Amiens, aucun transporteur n'intervient.
        $devis = $this->calculer(ShippingMethod::Pickup, 'FR', 40000, Money::fromCents(500000));

        $this->assertFalse($devis->isOnRequest());
        $this->assertSame(0, $this->centimes($devis));
    }

    public function test_la_remise_en_main_propre_ignore_le_pays(): void
    {
        // L'acheteur peut resider a l'etranger et venir chercher la piece.
        $devis = $this->calculer(ShippingMethod::Pickup, 'JP', 500, Money::fromCents(10000));

        $this->assertSame(0, $this->centimes($devis));
    }

    public function test_la_remise_en_main_propre_n_exige_pas_d_adresse(): void
    {
        $this->assertFalse(ShippingMethod::Pickup->requiresAddress());
        $this->assertTrue(ShippingMethod::Shipping->requiresAddress());
    }

    public function test_les_modes_de_remise_correspondent_aux_valeurs_de_la_base(): void
    {
        $this->assertSame(
            ['pickup', 'shipping'],
            array_map(static fn (ShippingMethod $m): string => $m->value, ShippingMethod::cases()),
        );
    }

    // ------------------------------------------------------------ assistance

    private function calculer(ShippingMethod $mode, string $pays, ?int $grammes, Money $sousTotal): ShippingQuote
    {
        return $this->calculateur()->quote($mode, $pays, $grammes, $sousTotal);
    }

    private function calculateur(): ShippingCalculator
    {
        return new ShippingCalculator(self::grilleAmorcee(), self::EMBALLAGE);
    }

    private function centimes(ShippingQuote $devis): int
    {
        $this->assertNotNull($devis->price, 'Le devis attendu porte un montant.');

        return $devis->price->cents;
    }

    /**
     * Grille tranchee le 2026-07-21 : forfait unique par zone, tranche a 10 kg.
     */
    private static function grilleAmorcee(): ShippingZones
    {
        return new ShippingZones(
            self::zoneFrance(),
            new ShippingZone(
                'EU',
                'Union européenne',
                'European Union',
                ['DE', 'BE', 'ES', 'IT', 'NL', 'LU', 'PT', 'AT', 'IE'],
                new WeightBracket(10000, Money::fromCents(2000), Money::fromCents(80000)),
            ),
            new ShippingZone(
                'WORLD',
                'Reste du monde',
                'Rest of the world',
                ['*'],
                new WeightBracket(10000, Money::fromCents(3500), null),
            ),
        );
    }

    private static function zoneFrance(): ShippingZone
    {
        return new ShippingZone(
            'FR',
            'France',
            'France',
            ['FR'],
            new WeightBracket(10000, Money::fromCents(900), Money::fromCents(30000)),
        );
    }
}
