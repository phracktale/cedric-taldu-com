<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Exception\MissingVatRate;
use App\Domain\Order\VatCategory;
use App\Domain\Order\VatRate;
use App\Domain\Order\VatRateTable;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Table des taux, historisee (03-boutique §5.3).
 *
 * Les taux ne sont jamais en dur dans le code : ils viennent de vat_rates avec
 * une periode de validite. Un changement de taux legal AJOUTE une ligne et clot
 * la precedente ; les commandes passees gardent le taux de leur date.
 */
#[CoversClass(VatRateTable::class)]
#[CoversClass(VatRate::class)]
#[CoversClass(VatCategory::class)]
final class VatRateTableTest extends TestCase
{
    public function test_le_taux_en_vigueur_est_rendu_pour_sa_categorie(): void
    {
        $table = self::tableDeReference();

        $this->assertSame(550, $table->rateFor(VatCategory::OriginalArtwork, new DateTimeImmutable('2026-07-21')));
        $this->assertSame(2000, $table->rateFor(VatCategory::StandardGoods, new DateTimeImmutable('2026-07-21')));
    }

    public function test_une_commande_anterieure_garde_l_ancien_taux(): void
    {
        // Le taux des œuvres originales est passe de 10 % a 5,5 % au
        // 1er janvier 2025. Rejouer une facture de 2024 doit produire le meme
        // document qu'a l'epoque (03-boutique §5.1).
        $table = self::tableDeReference();

        $this->assertSame(1000, $table->rateFor(VatCategory::OriginalArtwork, new DateTimeImmutable('2024-06-30')));
    }

    public function test_le_dernier_jour_d_une_periode_releve_encore_de_cette_periode(): void
    {
        // valid_to est inclusive : la ligne close au 2024-12-31 couvre encore
        // ce jour-la. Une borne exclusive laisserait un jour sans taux.
        $table = self::tableDeReference();

        $this->assertSame(1000, $table->rateFor(VatCategory::OriginalArtwork, new DateTimeImmutable('2024-12-31')));
        $this->assertSame(550, $table->rateFor(VatCategory::OriginalArtwork, new DateTimeImmutable('2025-01-01')));
    }

    public function test_l_heure_de_la_commande_n_influe_pas_sur_la_periode(): void
    {
        // valid_from et valid_to sont des DATE, pas des DATETIME. Une commande
        // passee a 23 h 59 le 31 decembre releve encore de l'ancien taux.
        $table = self::tableDeReference();

        $this->assertSame(
            1000,
            $table->rateFor(VatCategory::OriginalArtwork, new DateTimeImmutable('2024-12-31 23:59:59')),
        );
    }

    public function test_une_date_sans_taux_connu_leve_une_exception(): void
    {
        // Un trou dans la table est une erreur de donnees, pas un taux nul :
        // facturer 0 % par defaut ferait passer une commande taxable pour
        // exoneree, sans que personne ne s'en apercoive.
        $this->expectException(MissingVatRate::class);

        self::tableDeReference()->rateFor(VatCategory::OriginalPrint, new DateTimeImmutable('2020-01-01'));
    }

    public function test_une_table_vide_leve_une_exception(): void
    {
        $this->expectException(MissingVatRate::class);

        (new VatRateTable())->rateFor(VatCategory::StandardGoods, new DateTimeImmutable('2026-07-21'));
    }

    public function test_les_categories_correspondent_aux_valeurs_de_la_base(): void
    {
        // L'enum et l'ENUM MySQL doivent rester alignes : une valeur ajoutee
        // d'un cote seulement produirait une erreur d'ecriture en production.
        $this->assertSame(
            ['original_artwork', 'original_print', 'standard_goods'],
            array_map(static fn (VatCategory $c): string => $c->value, VatCategory::cases()),
        );
    }

    public function test_la_categorie_par_defaut_depend_du_type_d_objet(): void
    {
        // 01-modele §3 et §4 : une œuvre originale est en original_artwork, une
        // reproduction en standard_goods. Decision du 2026-07-21 : un gicle
        // rehausse reste une reproduction photomecanique.
        $this->assertSame(VatCategory::OriginalArtwork, VatCategory::defaultForArtwork());
        $this->assertSame(VatCategory::StandardGoods, VatCategory::defaultForProduct());
    }

    private static function tableDeReference(): VatRateTable
    {
        // Amorce de 01-modele §5.
        return new VatRateTable(
            new VatRate(VatCategory::OriginalArtwork, 1000, new DateTimeImmutable('2014-01-01'), new DateTimeImmutable('2024-12-31')),
            new VatRate(VatCategory::OriginalArtwork, 550, new DateTimeImmutable('2025-01-01'), null),
            new VatRate(VatCategory::OriginalPrint, 550, new DateTimeImmutable('2025-01-01'), null),
            new VatRate(VatCategory::StandardGoods, 2000, new DateTimeImmutable('2014-01-01'), null),
        );
    }
}
