<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidMoney;
use App\Domain\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Arithmetique monetaire du lot 3.
 *
 * Tout se joue en entiers de centimes. Aucune de ces methodes n'a le droit de
 * faire apparaitre un flottant : MoneyTypeTest scanne src/ pour s'en assurer,
 * mais c'est ici que la regle est reellement eprouvee.
 */
#[CoversClass(Money::class)]
final class MoneyArithmetiqueTest extends TestCase
{
    // ------------------------------------------------------------- additions

    public function test_deux_montants_s_additionnent(): void
    {
        $somme = Money::fromCents(4500)->plus(Money::fromCents(1250));

        $this->assertSame(5750, $somme->cents);
    }

    public function test_l_addition_ne_modifie_pas_les_operandes(): void
    {
        // ARCHITECTURE §4 : les entites du domaine sont immuables. Un total qui
        // muterait ses operandes ferait diverger deux calculs successifs sur le
        // meme panier.
        $prix = Money::fromCents(4500);

        $prix->plus(Money::fromCents(1250));

        $this->assertSame(4500, $prix->cents);
    }

    public function test_une_soustraction_donne_la_difference(): void
    {
        // Sert a deriver la TVA : vat = ttc - ht.
        $this->assertSame(2346, Money::fromCents(45000)->minus(Money::fromCents(42654))->cents);
    }

    public function test_une_soustraction_qui_passerait_sous_zero_est_refusee(): void
    {
        // Un montant negatif n'existe pas dans ce domaine (MoneyTest). Une
        // soustraction qui en produirait un revele une erreur de calcul en
        // amont : elle doit exploser, pas se propager silencieusement dans un
        // total de commande.
        $this->expectException(InvalidMoney::class);

        Money::fromCents(1000)->minus(Money::fromCents(1001));
    }

    public function test_une_somme_de_montants_vaut_leur_total(): void
    {
        $somme = Money::sum(Money::fromCents(4500), Money::fromCents(1250), Money::fromCents(1));

        $this->assertSame(5751, $somme->cents);
    }

    public function test_une_somme_sans_terme_vaut_zero(): void
    {
        // Le sous-total d'un panier vide. Sans ce cas, l'affichage du panier
        // vide leverait une erreur au lieu de montrer 0,00 €.
        $this->assertTrue(Money::sum()->isZero());
    }

    // --------------------------------------------------------- multiplication

    public function test_un_prix_unitaire_se_multiplie_par_une_quantite(): void
    {
        $this->assertSame(13500, Money::fromCents(4500)->times(3)->cents);
    }

    public function test_une_quantite_nulle_donne_un_montant_nul(): void
    {
        $this->assertTrue(Money::fromCents(4500)->times(0)->isZero());
    }

    public function test_une_quantite_negative_est_refusee(): void
    {
        // Aucune ligne de panier ni de commande n'a de quantite negative. Si
        // une entree en produisait une, la ligne deviendrait un avoir deguise.
        $this->expectException(InvalidMoney::class);

        Money::fromCents(4500)->times(-1);
    }

    // ------------------------------------------------------------ comparaison

    public function test_un_montant_atteint_un_seuil(): void
    {
        // Sert au franco de port : sous-total >= free_above_cents.
        $this->assertTrue(Money::fromCents(30000)->isAtLeast(Money::fromCents(30000)));
        $this->assertTrue(Money::fromCents(30001)->isAtLeast(Money::fromCents(30000)));
        $this->assertFalse(Money::fromCents(29999)->isAtLeast(Money::fromCents(30000)));
    }

    // ------------------------------------------------- extraction du HT (TVA)

    public function test_en_franchise_le_ht_est_egal_au_ttc(): void
    {
        // 03-boutique §5.1 : en franchise en base, vat_cents = 0. Un taux de
        // zero point de base doit donc laisser le montant intact.
        $this->assertSame(45000, Money::fromCents(45000)->excludingVat(0)->cents);
    }

    #[DataProvider('extractionsDeHt')]
    public function test_le_ht_se_derive_du_ttc_stocke(int $ttc, int $rateBps, int $htAttendu): void
    {
        // 03-boutique §5.4 : les prix sont stockes TTC, le HT est derive par
        // ht = ttc * 10000 / (10000 + rate_bps).
        $this->assertSame($htAttendu, Money::fromCents($ttc)->excludingVat($rateBps)->cents);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function extractionsDeHt(): iterable
    {
        yield 'original a 5,5 %' => [45000, 550, 42654];
        yield 'tirage a 20 %' => [12000, 2000, 10000];
        yield 'port a 20 %' => [900, 2000, 750];
        yield 'montant nul' => [0, 2000, 0];
        yield 'centime unique a 20 %' => [1, 2000, 1];
    }

    #[DataProvider('arrondisBancaires')]
    public function test_l_arrondi_du_ht_est_bancaire(int $ttc, int $htAttendu): void
    {
        // 07-tests-tdd §2.1 : « arrondi bancaire si un taux de TVA est
        // applique ». A exactement un demi-centime, on arrondit vers le pair,
        // et non systematiquement vers le haut : sur un grand nombre de lignes,
        // l'arrondi commercial biaise le total en faveur du vendeur.
        //
        // A 20 %, le demi-centime exact tombe sur les TTC congrus a 3 modulo 6.
        $this->assertSame($htAttendu, Money::fromCents($ttc)->excludingVat(2000)->cents);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function arrondisBancaires(): iterable
    {
        // 3 centimes TTC -> 2,5 centimes HT. Le pair est 2, l'arrondi
        // commercial aurait donne 3.
        yield 'demi vers le pair inferieur' => [3, 2];
        // 9 centimes TTC -> 7,5 centimes HT. Le pair est 8.
        yield 'demi vers le pair superieur' => [9, 8];
        // 15 centimes TTC -> 12,5 centimes HT. Le pair est 12.
        yield 'demi vers le pair inferieur, plus haut' => [15, 12];
    }

    public function test_un_taux_negatif_est_refuse(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::fromCents(45000)->excludingVat(-1);
    }

    // ------------------------------------------------ ventilation au prorata

    public function test_une_ventilation_exacte_ne_laisse_pas_de_reste(): void
    {
        // 03-boutique §5.5 : le port est ventile au prorata du HT de chaque
        // ligne.
        $parts = Money::fromCents(900)->allocate(3000, 1000);

        $this->assertSame([675, 225], self::centimes($parts));
    }

    public function test_le_reste_de_ventilation_va_a_la_ligne_la_plus_elevee(): void
    {
        // 03-boutique §5.5 : « les centimes d'arrondi de la ventilation sont
        // affectes a la ligne au montant le plus eleve ». 1000 reparti sur des
        // poids 1 et 2 donne 333 et 666 : le centime orphelin va au poids 2.
        $parts = Money::fromCents(1000)->allocate(1, 2);

        $this->assertSame([333, 667], self::centimes($parts));
    }

    public function test_a_poids_egaux_le_reste_va_a_la_premiere_ligne(): void
    {
        // Aucune ligne n'est « la plus elevee » : il faut une regle
        // deterministe, sinon deux calculs du meme panier peuvent differer.
        $parts = Money::fromCents(1000)->allocate(1, 1, 1);

        $this->assertSame([334, 333, 333], self::centimes($parts));
    }

    public function test_une_ventilation_conserve_toujours_le_total(): void
    {
        // L'invariant de 01-modele §7.6 : la somme des quote-parts de port doit
        // egaler orders.shipping_cents, au centime. Une ventilation qui perd ou
        // cree un centime casse le controle d'integrite de la commande.
        foreach ([1, 7, 99, 900, 1234, 99999] as $montant) {
            foreach ([[1, 1], [1, 2, 3], [7, 11, 13, 17], [1, 999999]] as $poids) {
                $parts = Money::fromCents($montant)->allocate(...$poids);

                $this->assertSame(
                    $montant,
                    array_sum(self::centimes($parts)),
                    "Ventilation de {$montant} sur " . implode('/', $poids),
                );
            }
        }
    }

    public function test_une_ventilation_sur_des_poids_nuls_va_a_la_premiere_ligne(): void
    {
        // Cas limite reel : un panier dont toutes les lignes sont a 0 € (offert)
        // mais qui porte des frais de port. Une division par la somme des poids
        // planterait ; le port doit rester attache a une ligne.
        $parts = Money::fromCents(500)->allocate(0, 0);

        $this->assertSame([500, 0], self::centimes($parts));
    }

    public function test_une_ventilation_d_un_montant_nul_donne_des_parts_nulles(): void
    {
        // Remise en main propre : port a 0 €.
        $parts = Money::zero()->allocate(3000, 1000);

        $this->assertSame([0, 0], self::centimes($parts));
    }

    public function test_une_ventilation_sans_ligne_est_refusee(): void
    {
        // Un port a ventiler sans aucune ligne signifie une commande vide qui
        // facture de l'expedition : c'est un defaut, pas un cas limite.
        $this->expectException(InvalidMoney::class);

        Money::fromCents(900)->allocate();
    }

    public function test_une_ventilation_sur_un_poids_negatif_est_refusee(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::fromCents(900)->allocate(1000, -1);
    }

    /**
     * @param list<Money> $parts
     * @return list<int>
     */
    private static function centimes(array $parts): array
    {
        return array_map(static fn (Money $part): int => $part->cents, $parts);
    }
}
