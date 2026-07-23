<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Locale;
use App\Domain\Money;
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
use App\Repository\OrderRepository;
use DateTimeImmutable;
use Tests\Support\SourceScanner;
use Tests\Support\DatabaseTestCase;

/**
 * 06-securite §8 : les jetons publics — consultation de commande, panier — sont
 * aleatoires sur 32 octets, compares en temps constant, et rien n'est
 * accessible sans eux.
 *
 * Une reference de commande se devine (CT-2026-0001) : seul le jeton protege la
 * lecture. Ce test verifie qu'aucun raccourci ne l'affaiblit.
 */
final class TokenTest extends DatabaseTestCase
{
    public function test_le_jeton_d_acces_d_une_commande_fait_32_octets(): void
    {
        $order = $this->creerCommande();

        // 64 caracteres hexadecimaux = 32 octets (06-securite §8).
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $order->accessToken);
    }

    public function test_deux_commandes_ont_des_jetons_sans_rapport(): void
    {
        $un = $this->creerCommande();
        $deux = $this->creerCommande();

        $this->assertNotSame($un->accessToken, $deux->accessToken);
    }

    public function test_une_commande_est_introuvable_sans_le_bon_jeton(): void
    {
        $order = $this->creerCommande();
        $repo = new OrderRepository($this->pdo);

        $this->assertNotNull($repo->findByReferenceAndToken($order->reference, $order->accessToken));
        $this->assertNull($repo->findByReferenceAndToken($order->reference, str_repeat('0', 64)));
        $this->assertNull($repo->findByReferenceAndToken($order->reference, ''));
        $this->assertNull($repo->findByReferenceAndToken($order->reference, substr($order->accessToken, 0, 63)));
    }

    public function test_la_comparaison_du_jeton_passe_par_hash_equals(): void
    {
        // 06-securite §8 : comparaison en TEMPS CONSTANT. Un « === » s'arreterait
        // au premier octet different et le temps trahirait le nombre d'octets
        // devines. Le code source doit donc employer hash_equals.
        $source = SourceScanner::withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Repository/OrderRepository.php'),
        );

        $this->assertStringContainsString('hash_equals', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/access_token[^;\n]*===|===[^;\n]*access_token/',
            $source,
            'Le jeton de commande ne doit jamais être comparé par === .',
        );
    }

    public function test_le_jeton_de_panier_fait_32_octets(): void
    {
        $repo = new \App\Repository\CartRepository($this->pdo);

        $cart = $repo->open(null, Locale::Fr);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cart->token);
    }

    // ------------------------------------------------------------ assistance

    private function creerCommande(): \App\Repository\PersistedOrder
    {
        static $numero = 0;
        ++$numero;

        $this->pdo->exec(
            'INSERT INTO categories (position, is_published, created_at, updated_at) VALUES (0, 1, NOW(), NOW())'
        );
        $category = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO artworks (category_id, reference, status, price_cents, weight_grams, is_published,
                                   created_at, updated_at)
             VALUES (:cat, :ref, :status, 45000, 800, 1, NOW(), NOW())'
        );
        $statement->execute([
            'cat' => $category,
            'ref' => 'REF-' . bin2hex(random_bytes(6)),
            'status' => 'available',
        ]);
        $artwork = (int) $this->pdo->lastInsertId();

        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Original, $artwork, 1);

        $catalogue = new ItemCatalogue(new PurchasableItem(
            kind: LineKind::Original,
            targetId: $artwork,
            label: 'Œuvre',
            sku: null,
            unitPrice: Money::fromCents(45000),
            vatCategory: VatCategory::OriginalArtwork,
            weightGrams: 800,
            isSellable: true,
            stockQty: null,
            editionsRemaining: null,
        ));

        $valuation = PricingPolicy::value($panier, $catalogue);
        $vat = VatPolicy::apply(
            VatMode::Exempt293b,
            new VatRateTable(new VatRate(VatCategory::OriginalArtwork, 550, new DateTimeImmutable('2025-01-01'), null)),
            new DateTimeImmutable('2026-07-23 10:00:00'),
            $valuation->taxableLines(),
            Money::zero(),
        );

        $draft = OrderDraft::fromValuation(
            valuation: $valuation,
            vat: $vat,
            locale: Locale::Fr,
            customerName: 'Acheteur',
            customerEmail: 'acheteur@example.test',
            customerPhone: null,
            shippingMethod: ShippingMethod::Pickup,
            shippingAddress: null,
            billingAddress: null,
            customerNote: null,
        );

        return (new OrderRepository($this->pdo))->create(
            $draft,
            new DateTimeImmutable('2026-07-23 10:0' . ($numero % 10) . ':00'),
        );
    }
}
