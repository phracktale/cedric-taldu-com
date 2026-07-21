<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\ArtworkStatus;
use App\Domain\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArtworkStatus::class)]
final class ArtworkStatusTest extends TestCase
{
    public function test_les_cinq_statuts_du_schema_sont_declares(): void
    {
        $this->assertSame(
            ['draft', 'available', 'reserved', 'sold', 'not_for_sale'],
            array_map(static fn (ArtworkStatus $s): string => $s->value, ArtworkStatus::cases())
        );
    }

    // ------------------------------------------------------------ visibilite

    #[DataProvider('statutsEtVisibilite')]
    public function test_seul_le_brouillon_est_invisible_du_public(ArtworkStatus $statut, bool $visible): void
    {
        // 06-securite §8 : une œuvre non publiee renvoie 404, pas 403. Une œuvre
        // vendue, elle, reste consultable — c'est le portfolio de l'artiste.
        $this->assertSame($visible, $statut->isPubliclyVisible());
    }

    /**
     * @return iterable<string, array{ArtworkStatus, bool}>
     */
    public static function statutsEtVisibilite(): iterable
    {
        yield 'brouillon' => [ArtworkStatus::Draft, false];
        yield 'disponible' => [ArtworkStatus::Available, true];
        yield 'réservée' => [ArtworkStatus::Reserved, true];
        yield 'vendue' => [ArtworkStatus::Sold, true];
        yield 'hors commerce' => [ArtworkStatus::NotForSale, true];
    }

    // -------------------------------------------------------------- achat

    #[DataProvider('statutsEtAchat')]
    public function test_seule_une_œuvre_disponible_peut_etre_acquise(ArtworkStatus $statut, bool $achetable): void
    {
        $this->assertSame($achetable, $statut->isPurchasable());
    }

    /**
     * @return iterable<string, array{ArtworkStatus, bool}>
     */
    public static function statutsEtAchat(): iterable
    {
        yield 'brouillon' => [ArtworkStatus::Draft, false];
        yield 'disponible' => [ArtworkStatus::Available, true];
        // Une reservation court pendant le paiement d'un autre visiteur : le
        // bouton disparait, sans quoi deux acheteurs paieraient la meme piece.
        yield 'réservée' => [ArtworkStatus::Reserved, false];
        yield 'vendue' => [ArtworkStatus::Sold, false];
        yield 'hors commerce' => [ArtworkStatus::NotForSale, false];
    }

    // ------------------------------------------------------------ pastille

    public function test_la_pastille_n_est_affichee_que_lorsqu_elle_informe(): void
    {
        // 02-front-public §3 : « Disponible en boutique » sur la vignette,
        // « Vendue » sur la fiche. Un brouillon n'atteint jamais le public et
        // une œuvre hors commerce n'a pas de statut marchand a annoncer.
        $this->assertTrue(ArtworkStatus::Available->hasBadge());
        $this->assertTrue(ArtworkStatus::Reserved->hasBadge());
        $this->assertTrue(ArtworkStatus::Sold->hasBadge());
        $this->assertFalse(ArtworkStatus::NotForSale->hasBadge());
        $this->assertFalse(ArtworkStatus::Draft->hasBadge());
    }

    #[DataProvider('libellesFrancais')]
    public function test_le_libelle_francais(ArtworkStatus $statut, string $attendu): void
    {
        $this->assertSame($attendu, $statut->label(Locale::Fr));
    }

    /**
     * @return iterable<string, array{ArtworkStatus, string}>
     */
    public static function libellesFrancais(): iterable
    {
        yield 'disponible' => [ArtworkStatus::Available, 'Disponible'];
        yield 'réservée' => [ArtworkStatus::Reserved, 'Réservée'];
        yield 'vendue' => [ArtworkStatus::Sold, 'Vendue'];
        yield 'hors commerce' => [ArtworkStatus::NotForSale, 'Non disponible à la vente'];
    }

    #[DataProvider('libellesAnglais')]
    public function test_le_libelle_anglais(ArtworkStatus $statut, string $attendu): void
    {
        $this->assertSame($attendu, $statut->label(Locale::En));
    }

    /**
     * @return iterable<string, array{ArtworkStatus, string}>
     */
    public static function libellesAnglais(): iterable
    {
        yield 'disponible' => [ArtworkStatus::Available, 'Available'];
        yield 'réservée' => [ArtworkStatus::Reserved, 'Reserved'];
        yield 'vendue' => [ArtworkStatus::Sold, 'Sold'];
        yield 'hors commerce' => [ArtworkStatus::NotForSale, 'Not for sale'];
    }

    public function test_chaque_statut_a_un_libelle_dans_les_deux_langues(): void
    {
        // Une clé manquante ne doit pas se decouvrir sur la page anglaise.
        foreach (ArtworkStatus::cases() as $statut) {
            foreach (Locale::cases() as $langue) {
                $this->assertNotSame('', $statut->label($langue), $statut->value . ' / ' . $langue->value);
            }
        }
    }

    // ------------------------------------------------- donnees structurees

    public function test_la_disponibilite_schema_org_suit_le_statut(): void
    {
        // 05-i18n-seo §5 : availability InStock / SoldOut selon le statut.
        $this->assertSame('https://schema.org/InStock', ArtworkStatus::Available->schemaAvailability());
        $this->assertSame('https://schema.org/SoldOut', ArtworkStatus::Sold->schemaAvailability());
        $this->assertSame('https://schema.org/SoldOut', ArtworkStatus::Reserved->schemaAvailability());
    }
}
