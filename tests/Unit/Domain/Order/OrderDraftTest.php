<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Exception\InvalidAddress;
use App\Domain\Exception\MisalignedOrderDraft;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\Address;
use App\Domain\Order\OrderDraft;
use App\Domain\Order\VatCategory;
use App\Domain\Order\VatMode;
use App\Domain\Order\VatPolicy;
use App\Domain\Order\VatRate;
use App\Domain\Order\VatRateTable;
use App\Domain\Shipping\ShippingMethod;
use App\Domain\Shop\Cart;
use App\Domain\Shop\ItemCatalogue;
use App\Domain\Shop\LineKind;
use App\Domain\Shop\PricingPolicy;
use App\Domain\Shop\PurchasableItem;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Brouillon de commande : ce qui sera FIGE en base.
 *
 * Il rassemble deux calculs distincts — la valorisation du panier, qui sait ce
 * qui est vendu, et la ventilation de TVA, qui sait combien — et les apparie
 * ligne a ligne. C'est le seul endroit ou cet appariement a lieu, et il refuse
 * de deviner : deux listes de longueurs differentes sont un defaut, pas un cas
 * a rattraper.
 */
#[CoversClass(OrderDraft::class)]
#[CoversClass(Address::class)]
final class OrderDraftTest extends TestCase
{
    public function test_chaque_ligne_porte_son_identite_et_ses_montants(): void
    {
        $brouillon = $this->brouillon();

        $this->assertCount(2, $brouillon->lines);

        $original = $brouillon->lines[0];
        $this->assertSame(LineKind::Original, $original->kind);
        $this->assertSame(7, $original->artworkId);
        $this->assertNull($original->variantId);
        $this->assertSame('Articulation', $original->label);
        $this->assertNull($original->sku);
        $this->assertSame(45000, $original->total->cents);

        $tirage = $brouillon->lines[1];
        $this->assertSame(LineKind::Reproduction, $tirage->kind);
        $this->assertNull($tirage->artworkId);
        $this->assertSame(12, $tirage->variantId);
        $this->assertSame('ART-3040', $tirage->sku);
        $this->assertSame(2, $tirage->quantity);
    }

    public function test_les_totaux_de_commande_viennent_de_la_ventilation(): void
    {
        // Aucun total n'est recalcule ici : le faire ouvrirait la porte a deux
        // resultats differents pour la meme commande.
        $brouillon = $this->brouillon();

        $this->assertSame(57000, $brouillon->subtotal->cents);
        $this->assertSame(900, $brouillon->shipping->cents);
        $this->assertSame(57900, $brouillon->total->cents);
        $this->assertSame(VatMode::Exempt293b, $brouillon->vatMode);
    }

    public function test_en_franchise_tous_les_taux_de_ligne_sont_nuls(): void
    {
        foreach ($this->brouillon()->lines as $ligne) {
            $this->assertSame(0, $ligne->vatRateBps);
            $this->assertSame(0, $ligne->vat->cents);
            $this->assertSame(0, $ligne->shippingVat->cents);
        }
    }

    public function test_les_quote_parts_de_port_somment_au_port_de_la_commande(): void
    {
        // 01-modele §7.6, verifie a la source plutot qu'apres l'insertion.
        $brouillon = $this->brouillon();

        $somme = 0;

        foreach ($brouillon->lines as $ligne) {
            $somme += $ligne->shippingShare->cents;
        }

        $this->assertSame($brouillon->shipping->cents, $somme);
    }

    public function test_deux_listes_de_longueurs_differentes_sont_refusees(): void
    {
        // L'appariement se fait par indice. Si les deux calculs divergent, la
        // ligne 2 recevrait les montants de la ligne 1 : une commande fausse,
        // et figee. Mieux vaut echouer bruyamment.
        $valorisation = PricingPolicy::value($this->panier(), $this->catalogue());

        $ventilation = VatPolicy::apply(
            VatMode::Exempt293b,
            $this->taux(),
            new DateTimeImmutable('2026-07-22 10:00:00'),
            [array_slice($valorisation->taxableLines(), 0, 1)[0]],
            Money::zero(),
        );

        $this->expectException(MisalignedOrderDraft::class);

        OrderDraft::fromValuation(
            valuation: $valorisation,
            vat: $ventilation,
            locale: Locale::Fr,
            customerName: 'Acheteur',
            customerEmail: 'acheteur@example.test',
            customerPhone: null,
            shippingMethod: ShippingMethod::Pickup,
            shippingAddress: null,
            billingAddress: null,
            customerNote: null,
        );
    }

    public function test_une_commande_sans_ligne_est_refusee(): void
    {
        $vide = PricingPolicy::value(Cart::empty('jeton', Locale::Fr), new ItemCatalogue());

        $this->expectException(MisalignedOrderDraft::class);

        OrderDraft::fromValuation(
            valuation: $vide,
            vat: VatPolicy::apply(
                VatMode::Exempt293b,
                $this->taux(),
                new DateTimeImmutable('2026-07-22 10:00:00'),
                [$this->ligneTaxable()],
                Money::zero(),
            ),
            locale: Locale::Fr,
            customerName: 'Acheteur',
            customerEmail: 'acheteur@example.test',
            customerPhone: null,
            shippingMethod: ShippingMethod::Pickup,
            shippingAddress: null,
            billingAddress: null,
            customerNote: null,
        );
    }

    // ------------------------------------------------------------- adresses

    public function test_une_adresse_se_reduit_aux_cinq_champs_du_modele(): void
    {
        $adresse = new Address('12 rue des Trois-Cailloux', null, '80000', 'Amiens', 'FR');

        $this->assertSame(
            ['line1', 'line2', 'postal_code', 'city', 'country'],
            array_keys($adresse->toArray()),
        );
        $this->assertSame('FR', $adresse->toArray()['country']);
    }

    public function test_le_code_pays_est_normalise_en_majuscules(): void
    {
        // Le pays sert a choisir la zone d'expedition : « fr » enverrait la
        // commande en zone Monde et la surfacturerait de 26 €.
        $this->assertSame('FR', (new Address('12 rue', null, '80000', 'Amiens', 'fr'))->country);
    }

    #[DataProvider('adressesAvecSautDeLigne')]
    public function test_un_saut_de_ligne_dans_une_adresse_est_refuse(
        string $line1,
        ?string $line2,
        string $postalCode,
        string $city,
        string $country,
    ): void {
        // 06-securite §6.6 : toute valeur entrant dans un e-mail est purgee de
        // ses CR/LF. L'adresse figure dans l'e-mail de commande, donc la regle
        // s'applique DES LA CONSTRUCTION — pas au moment de l'envoi, ou on
        // aurait deja oublie d'y penser.
        $this->expectException(InvalidAddress::class);

        new Address($line1, $line2, $postalCode, $city, $country);
    }

    /**
     * @return iterable<string, array{string, ?string, string, string, string}>
     */
    public static function adressesAvecSautDeLigne(): iterable
    {
        yield 'rue' => ["12 rue\r\nBcc: pirate@example.test", null, '80000', 'Amiens', 'FR'];
        yield 'complément' => ['12 rue', "2e étage\n", '80000', 'Amiens', 'FR'];
        yield 'code postal' => ['12 rue', null, "80000\r", 'Amiens', 'FR'];
        yield 'ville' => ['12 rue', null, '80000', "Amiens\n", 'FR'];
        yield 'pays' => ['12 rue', null, '80000', 'Amiens', "F\nR"];
    }

    public function test_un_code_pays_qui_n_en_est_pas_un_est_refuse(): void
    {
        $this->expectException(InvalidAddress::class);

        new Address('12 rue', null, '80000', 'Amiens', 'FRANCE');
    }

    public function test_une_adresse_sans_rue_est_refusee(): void
    {
        $this->expectException(InvalidAddress::class);

        new Address('   ', null, '80000', 'Amiens', 'FR');
    }

    // ------------------------------------------------------------ assistance

    private function brouillon(): OrderDraft
    {
        $valorisation = PricingPolicy::value($this->panier(), $this->catalogue());

        $ventilation = VatPolicy::apply(
            VatMode::Exempt293b,
            $this->taux(),
            new DateTimeImmutable('2026-07-22 10:00:00'),
            $valorisation->taxableLines(),
            Money::fromCents(900),
        );

        return OrderDraft::fromValuation(
            valuation: $valorisation,
            vat: $ventilation,
            locale: Locale::Fr,
            customerName: 'Acheteur',
            customerEmail: 'acheteur@example.test',
            customerPhone: null,
            shippingMethod: ShippingMethod::Shipping,
            shippingAddress: new Address('12 rue des Trois-Cailloux', null, '80000', 'Amiens', 'FR'),
            billingAddress: null,
            customerNote: null,
        );
    }

    private function panier(): Cart
    {
        return Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 7, 1)
            ->add(LineKind::Reproduction, 12, 2);
    }

    private function catalogue(): ItemCatalogue
    {
        return new ItemCatalogue(
            new PurchasableItem(
                kind: LineKind::Original,
                targetId: 7,
                label: 'Articulation',
                sku: null,
                unitPrice: Money::fromCents(45000),
                vatCategory: VatCategory::OriginalArtwork,
                weightGrams: 800,
                isSellable: true,
                stockQty: null,
                editionsRemaining: null,
            ),
            new PurchasableItem(
                kind: LineKind::Reproduction,
                targetId: 12,
                label: 'Tirage d’art — 30 × 40 cm',
                sku: 'ART-3040',
                unitPrice: Money::fromCents(6000),
                vatCategory: VatCategory::StandardGoods,
                weightGrams: 300,
                isSellable: true,
                stockQty: 10,
                editionsRemaining: null,
            ),
        );
    }

    private function ligneTaxable(): \App\Domain\Order\TaxableLine
    {
        return new \App\Domain\Order\TaxableLine(
            VatCategory::OriginalArtwork,
            Money::fromCents(45000),
            1,
        );
    }

    private function taux(): VatRateTable
    {
        return new VatRateTable(
            new VatRate(VatCategory::OriginalArtwork, 550, new DateTimeImmutable('2025-01-01'), null),
            new VatRate(VatCategory::StandardGoods, 2000, new DateTimeImmutable('2014-01-01'), null),
        );
    }
}
