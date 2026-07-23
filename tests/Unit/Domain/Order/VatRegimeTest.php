<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Locale;
use App\Domain\Order\VatMode;
use App\Domain\Order\VatRegime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regime de TVA applicable a une commande (03-boutique §5.1).
 *
 * Deux reglages et une date. Le regime est determine a la creation de la
 * commande puis FIGE dans orders.vat_mode : rejouer une facture de 2026 en 2028
 * doit produire exactement le meme document (01-modele §7.7).
 */
#[CoversClass(VatRegime::class)]
#[CoversClass(VatMode::class)]
final class VatRegimeTest extends TestCase
{
    public function test_par_defaut_l_artiste_est_en_franchise_en_base(): void
    {
        // Decision du 2026-07-21 : le site demarre en franchise, et ce choix est
        // definitif pour toutes les commandes de la periode.
        $regime = new VatRegime(VatMode::Exempt293b, null);

        $this->assertSame(VatMode::Exempt293b, $regime->modeAt(new DateTimeImmutable('2026-07-21')));
    }

    public function test_en_franchise_la_date_de_bascule_ne_change_rien(): void
    {
        // Tant que vat.mode vaut exempt_293b, aucune commande n'est taxee, meme
        // posterieure a une date de bascule saisie par avance. La bascule
        // demande DEUX reglages : sans quoi une date entree par erreur
        // declencherait la taxation a l'insu de l'artiste.
        $regime = new VatRegime(VatMode::Exempt293b, new DateTimeImmutable('2027-01-01'));

        $this->assertSame(VatMode::Exempt293b, $regime->modeAt(new DateTimeImmutable('2027-06-15')));
    }

    public function test_apres_la_bascule_une_commande_est_taxee(): void
    {
        $regime = new VatRegime(VatMode::Taxed, new DateTimeImmutable('2027-01-01'));

        $this->assertSame(VatMode::Taxed, $regime->modeAt(new DateTimeImmutable('2027-01-01')));
        $this->assertSame(VatMode::Taxed, $regime->modeAt(new DateTimeImmutable('2027-06-15')));
    }

    public function test_une_commande_anterieure_a_la_bascule_reste_en_franchise(): void
    {
        // 03-boutique §5.1 : « Une commande passee AVANT vat.taxable_from reste
        // en franchise pour toujours ». C'est ce qui permet de basculer sans
        // reprise de donnees.
        $regime = new VatRegime(VatMode::Taxed, new DateTimeImmutable('2027-01-01'));

        $this->assertSame(VatMode::Exempt293b, $regime->modeAt(new DateTimeImmutable('2026-12-31 23:59:59')));
    }

    public function test_un_regime_taxe_sans_date_de_bascule_taxe_tout(): void
    {
        // Cas d'un artiste deja assujetti au demarrage du site.
        $regime = new VatRegime(VatMode::Taxed, null);

        $this->assertSame(VatMode::Taxed, $regime->modeAt(new DateTimeImmutable('2020-01-01')));
    }

    public function test_la_mention_293_b_n_existe_qu_en_franchise(): void
    {
        // 03-boutique §5.8 : « Aucun taux et aucune mention legale n'existe
        // ailleurs dans le code. » La mention est portee par le mode, elle
        // n'est pas recopiee dans un gabarit.
        $this->assertSame(
            'TVA non applicable, article 293 B du CGI',
            VatMode::Exempt293b->legalMention(Locale::Fr),
        );
        $this->assertNull(VatMode::Taxed->legalMention(Locale::Fr));
    }

    public function test_la_mention_293_b_existe_aussi_en_anglais(): void
    {
        // La mention reste en francais — c'est une reference au droit francais —
        // mais elle est introduite par une glose comprehensible.
        $mention = VatMode::Exempt293b->legalMention(Locale::En);

        $this->assertNotNull($mention);
        $this->assertStringContainsString('293 B', $mention);
    }

    public function test_les_modes_correspondent_aux_valeurs_de_la_base(): void
    {
        $this->assertSame(
            ['exempt_293b', 'taxed'],
            array_map(static fn (VatMode $m): string => $m->value, VatMode::cases()),
        );
    }
}
