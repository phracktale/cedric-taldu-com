<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\OrderDraft;
use App\Domain\Order\OrderStatus;
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
use App\Core\Config;
use App\Core\Env;
use App\Core\Router;
use App\Repository\FulfillmentRepository;
use App\Repository\OrderRepository;
use App\Repository\StockRepository;
use App\Repository\StripeEventRepository;
use App\Service\Fulfillment\Exception\ProdigiException;
use App\Service\Fulfillment\FakeProdigiClient;
use App\Service\Fulfillment\FulfillmentService;
use App\Service\Fulfillment\PrintAssetUrl;
use App\Service\Fulfillment\ProdigiConfig;
use App\Service\I18n\UrlGenerator;
use App\Service\Payment\FakeGateway;
use App\Service\Payment\PaymentEventHandler;
use App\Service\Payment\WebhookOutcome;
use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\RecordingLogger;

/**
 * Traitement d'un evenement Stripe verifie (03-boutique §6).
 *
 * C'est le seul chemin par lequel une commande devient `paid`, une œuvre
 * devient `sold` et un stock est decremente (03-boutique §8.3). Tout se joue
 * en une transaction, et tout doit rester idempotent au rejeu — Stripe
 * REESSAIE.
 */
final class PaymentEventHandlerTest extends DatabaseTestCase
{
    private const SECRET = 'whsec_test';
    private const MAINTENANT = '2026-07-22 10:00:00';

    private PaymentEventHandler $handler;
    private FakeGateway $gateway;
    private OrderRepository $orders;
    private RecordingLogger $logger;

    private int $artwork;
    private int $variant;
    private int $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway(self::SECRET);
        $this->orders = new OrderRepository($this->pdo);
        $this->logger = new RecordingLogger();

        $this->handler = new PaymentEventHandler(
            $this->pdo,
            new StripeEventRepository($this->pdo),
            $this->orders,
            new StockRepository($this->pdo),
            $this->logger,
        );

        $this->creerCatalogue();
    }

    // ------------------------------------------------------ paiement reussi

    public function test_un_paiement_confirme_passe_la_commande_a_payee(): void
    {
        $commande = $this->creerCommande();

        $this->assertSame(WebhookOutcome::Processed, $this->traiter($commande->reference, $commande->id));

        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertNotNull($this->valeur("SELECT paid_at FROM orders WHERE id = {$commande->id}"));
    }

    public function test_un_paiement_confirme_vend_l_oeuvre_originale(): void
    {
        $commande = $this->creerCommande();

        $this->traiter($commande->reference, $commande->id);

        $this->assertSame('sold', $this->valeur("SELECT status FROM artworks WHERE id = {$this->artwork}"));
        $this->assertNull($this->valeur("SELECT reserved_until FROM artworks WHERE id = {$this->artwork}"));
    }

    public function test_un_paiement_confirme_decremente_le_stock(): void
    {
        $commande = $this->creerCommande();

        $this->traiter($commande->reference, $commande->id);

        // 10 en stock, 2 achetes.
        $this->assertSame(
            8,
            (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$this->variant}"),
        );
    }

    public function test_un_paiement_confirme_attribue_les_numeros_d_edition(): void
    {
        $commande = $this->creerCommande();

        $this->traiter($commande->reference, $commande->id);

        $this->assertSame(
            2,
            (int) $this->valeur("SELECT editions_sold FROM products WHERE id = {$this->product}"),
        );
        $this->assertSame(
            1,
            (int) $this->valeur(
                "SELECT edition_number FROM order_items WHERE order_id = {$commande->id} AND kind = 'reproduction'"
            ),
        );
    }

    public function test_l_identifiant_de_paiement_est_conserve(): void
    {
        $commande = $this->creerCommande();

        $this->traiter($commande->reference, $commande->id);

        $this->assertSame(
            'pi_test_1',
            $this->valeur("SELECT stripe_payment_intent_id FROM orders WHERE id = {$commande->id}"),
        );
    }

    // ------------------------------------------------------------ idempotence

    public function test_le_rejeu_du_meme_evenement_n_a_aucun_second_effet(): void
    {
        // 01-modele §7.8 et 03-boutique §6.3. Stripe REESSAIE : c'est le
        // scenario nominal, pas un cas limite.
        $commande = $this->creerCommande();

        $this->traiter($commande->reference, $commande->id, 'evt_1');
        $second = $this->traiter($commande->reference, $commande->id, 'evt_1');

        $this->assertSame(WebhookOutcome::AlreadyHandled, $second);
        $this->assertSame(
            8,
            (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$this->variant}"),
            'Le stock ne doit pas etre decremente deux fois.',
        );
        $this->assertSame(
            2,
            (int) $this->valeur("SELECT editions_sold FROM products WHERE id = {$this->product}"),
        );
    }

    public function test_un_evenement_distinct_sur_une_commande_deja_payee_ne_refait_rien(): void
    {
        // Deux evenements differents, meme commande : la protection ne peut
        // pas venir de stripe_events seul. Ce sont les UPDATE conditionnels
        // qui tiennent.
        $commande = $this->creerCommande();

        $this->traiter($commande->reference, $commande->id, 'evt_1');
        $this->traiter($commande->reference, $commande->id, 'evt_2');

        $this->assertSame(
            8,
            (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$this->variant}"),
        );
        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
    }

    // --------------------------------------------------------------- anomalies

    public function test_une_oeuvre_deja_vendue_marque_la_commande_payee_et_en_anomalie(): void
    {
        // 03-boutique §8.5 : deux acheteurs paient la meme œuvre. Le second
        // voit sa commande PAYEE — il a bien paye — et signalee pour
        // remboursement manuel. « On ne perd jamais un paiement encaisse. »
        $commande = $this->creerCommande();
        $this->pdo->exec("UPDATE artworks SET status = 'sold' WHERE id = {$this->artwork}");

        $this->assertSame(WebhookOutcome::Processed, $this->traiter($commande->reference, $commande->id));

        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertStringContainsString(
            'CT-2026-0001',
            (string) $this->valeur("SELECT anomaly_note FROM orders WHERE id = {$commande->id}"),
        );
        $this->assertSame(
            'already_sold',
            $this->valeur(
                "SELECT anomaly FROM order_items WHERE order_id = {$commande->id} AND kind = 'original'"
            ),
        );
    }

    public function test_un_stock_insuffisant_marque_la_commande_payee_et_en_anomalie(): void
    {
        $commande = $this->creerCommande();
        $this->pdo->exec("UPDATE product_variants SET stock_qty = 1 WHERE id = {$this->variant}");

        $this->traiter($commande->reference, $commande->id);

        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame(
            'stock_missing',
            $this->valeur(
                "SELECT anomaly FROM order_items WHERE order_id = {$commande->id} AND kind = 'reproduction'"
            ),
        );
        // Le stock n'a PAS ete decremente partiellement.
        $this->assertSame(
            1,
            (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$this->variant}"),
        );
    }

    public function test_une_edition_epuisee_marque_la_commande_payee_et_en_anomalie(): void
    {
        $commande = $this->creerCommande();
        $this->pdo->exec("UPDATE products SET editions_sold = 30 WHERE id = {$this->product}");

        $this->traiter($commande->reference, $commande->id);

        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame(
            'edition_exhausted',
            $this->valeur(
                "SELECT anomaly FROM order_items WHERE order_id = {$commande->id} AND kind = 'reproduction'"
            ),
        );
    }

    // ------------------------------------------------------ echec et abandon

    public function test_une_session_expiree_annule_la_commande_et_libere_l_oeuvre(): void
    {
        $commande = $this->creerCommande();

        $this->assertSame(
            WebhookOutcome::Processed,
            $this->traiter($commande->reference, $commande->id, 'evt_1', 'checkout.session.expired'),
        );

        $this->assertSame('cancelled', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame('available', $this->valeur("SELECT status FROM artworks WHERE id = {$this->artwork}"));
    }

    public function test_un_paiement_echoue_marque_la_commande_et_libere_l_oeuvre(): void
    {
        $commande = $this->creerCommande();

        $this->traiter($commande->reference, $commande->id, 'evt_1', 'payment_intent.payment_failed');

        $this->assertSame('failed', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame('available', $this->valeur("SELECT status FROM artworks WHERE id = {$this->artwork}"));
    }

    public function test_une_expiration_arrivee_apres_le_paiement_ne_remet_rien_en_vente(): void
    {
        // Le cas dangereux : Stripe peut livrer `expired` APRES `completed`.
        // Remettre l'œuvre en vente la vendrait deux fois.
        $commande = $this->creerCommande();
        $this->traiter($commande->reference, $commande->id, 'evt_1');

        $this->traiter($commande->reference, $commande->id, 'evt_2', 'checkout.session.expired');

        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame('sold', $this->valeur("SELECT status FROM artworks WHERE id = {$this->artwork}"));
    }

    public function test_un_remboursement_ne_reintegre_aucun_stock(): void
    {
        // 03-boutique §6 : « aucune reintegration automatique de stock,
        // decision de l'artiste, faite en back-office ».
        $commande = $this->creerCommande();
        $this->traiter($commande->reference, $commande->id, 'evt_1');

        $this->traiter($commande->reference, $commande->id, 'evt_2', 'charge.refunded');

        $this->assertSame('refunded', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame('sold', $this->valeur("SELECT status FROM artworks WHERE id = {$this->artwork}"));
        $this->assertSame(
            8,
            (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$this->variant}"),
        );
    }

    // ------------------------------------------------------------- cas limites

    public function test_un_type_d_evenement_inconnu_est_acquitte_sans_effet(): void
    {
        // Stripe emet des dizaines de types. Repondre autre chose que 200
        // ferait reessayer indefiniment un evenement qui ne nous concerne pas.
        $commande = $this->creerCommande();

        $this->assertSame(
            WebhookOutcome::Ignored,
            $this->traiter($commande->reference, $commande->id, 'evt_1', 'customer.created'),
        );

        $this->assertSame('pending', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
    }

    public function test_une_reference_de_commande_inconnue_est_acquittee_et_journalisee(): void
    {
        // Un evenement d'un autre site partageant la meme cle de webhook, ou
        // une commande effacee. Reessayer n'y changerait rien.
        $this->assertSame(WebhookOutcome::Ignored, $this->traiter('CT-2026-9999', 99999));

        $this->assertNotSame([], $this->logger->entries);
    }

    public function test_un_paiement_non_acquitte_ne_vend_rien(): void
    {
        // `checkout.session.completed` peut arriver avec `payment_status` a
        // `unpaid` (virement en cours). Vendre alors serait vendre a credit.
        $commande = $this->creerCommande();

        $this->traiter($commande->reference, $commande->id, 'evt_1', 'checkout.session.completed', 'unpaid');

        $this->assertSame('pending', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame('reserved', $this->valeur("SELECT status FROM artworks WHERE id = {$this->artwork}"));
    }

    // ------------------------------------------------------------- assistance

    // ---------------------------------------------------- soumission Prodigi

    public function test_une_reproduction_mappee_est_soumise_a_prodigi(): void
    {
        $client = new FakeProdigiClient();
        $this->activerFulfillment($client);
        $this->mapperVariante('GLOBAL-HGE-16X20');

        $commande = $this->creerCommande();
        $this->adresser($commande->id);

        $this->traiter($commande->reference, $commande->id);

        $this->assertCount(1, $client->orders);
        $charge = $client->lastOrder();
        $this->assertNotNull($charge);
        $this->assertSame('GLOBAL-HGE-16X20', $charge['items'][0]['sku']);
        $this->assertSame(2, $charge['items'][0]['copies']);
        $this->assertNotNull($this->valeur("SELECT prodigi_order_id FROM orders WHERE id = {$commande->id}"));
    }

    public function test_une_reproduction_sans_sku_prodigi_n_est_pas_soumise(): void
    {
        $client = new FakeProdigiClient();
        $this->activerFulfillment($client);
        // Pas de mapping : la variante n'a pas de SKU Prodigi.

        $commande = $this->creerCommande();
        $this->adresser($commande->id);

        $this->traiter($commande->reference, $commande->id);

        $this->assertCount(0, $client->orders);
        $this->assertNull($this->valeur("SELECT prodigi_order_id FROM orders WHERE id = {$commande->id}"));
    }

    public function test_un_echec_prodigi_ne_perd_pas_le_paiement(): void
    {
        // L'invariant capital : Prodigi tombe, la commande reste PAYÉE.
        $client = new FakeProdigiClient();
        $client->failWith(new ProdigiException('panne'));
        $this->activerFulfillment($client);
        $this->mapperVariante();

        $commande = $this->creerCommande();
        $this->adresser($commande->id);

        $this->traiter($commande->reference, $commande->id);

        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertNull($this->valeur("SELECT prodigi_order_id FROM orders WHERE id = {$commande->id}"));
    }

    public function test_une_commande_deja_soumise_n_est_pas_resoumise(): void
    {
        $client = new FakeProdigiClient();
        $service = $this->activerFulfillment($client);
        $this->mapperVariante();

        $commande = $this->creerCommande();
        $this->adresser($commande->id);
        $frais = $this->orders->findById($commande->id);
        $this->assertNotNull($frais);

        $service->submit($frais, new DateTimeImmutable(self::MAINTENANT));
        $service->submit($frais, new DateTimeImmutable(self::MAINTENANT));

        $this->assertCount(1, $client->orders);
    }

    private function activerFulfillment(FakeProdigiClient $client, string $cle = 'sk-sandbox'): FulfillmentService
    {
        $racine = dirname(__DIR__, 2);
        /** @var list<\App\Core\Route> $routes */
        $routes = require $racine . '/config/routes.php';

        $url = new UrlGenerator(
            new Router($routes),
            Config::fromEnv(Env::fromArray([
                'APP_ENV' => 'preprod',
                'APP_DEBUG' => '0',
                'APP_URL' => 'https://example.test',
                'APP_BASE_PATH' => '',
                'APP_DEFAULT_LOCALE' => 'fr',
                'APP_LOCALES' => 'fr,en',
                'TRUSTED_PROXIES' => '',
                'SECURITY_PEPPER' => str_repeat('a', 64),
            ])),
            '',
            $racine . '/public',
        );

        $service = new FulfillmentService(
            $client,
            ProdigiConfig::resolve('sandbox', 'preprod', ['sandboxKey' => $cle, 'liveKey' => '']),
            new FulfillmentRepository($this->pdo),
            new PrintAssetUrl('secret-impression'),
            $url,
            $this->logger,
        );

        // Le handler par défaut n'a pas de fulfillment : on le remplace par un
        // handler qui le porte, sans toucher aux autres tests.
        $this->handler = new PaymentEventHandler(
            $this->pdo,
            new StripeEventRepository($this->pdo),
            $this->orders,
            new StockRepository($this->pdo),
            $this->logger,
            null,
            null,
            $service,
        );

        return $service;
    }

    private function mapperVariante(string $sku = 'GLOBAL-HGE-16X20'): void
    {
        $this->pdo->prepare('UPDATE product_variants SET prodigi_sku = :sku WHERE id = :id')
            ->execute(['sku' => $sku, 'id' => $this->variant]);
        $this->pdo->prepare(
            "UPDATE artworks SET print_asset_path = 'print/aa/bb/x.jpg', print_asset_mime = 'image/jpeg' WHERE id = :id"
        )->execute(['id' => $this->artwork]);
    }

    private function adresser(int $orderId): void
    {
        $adresse = json_encode([
            'line1' => '1 rue Test', 'line2' => null,
            'postal_code' => '75001', 'city' => 'Paris', 'country' => 'FR',
        ], JSON_THROW_ON_ERROR);

        $this->pdo->prepare("UPDATE orders SET shipping_method = 'shipping', shipping_address = :a WHERE id = :id")
            ->execute(['a' => $adresse, 'id' => $orderId]);
    }

    private function traiter(
        string $reference,
        int $orderId,
        string $eventId = 'evt_1',
        string $type = 'checkout.session.completed',
        string $paymentStatus = 'paid',
    ): WebhookOutcome {
        $corps = json_encode([
            'id' => $eventId,
            'type' => $type,
            'data' => ['object' => [
                'id' => 'cs_test_' . $orderId,
                'client_reference_id' => $reference,
                'payment_status' => $paymentStatus,
                'payment_intent' => 'pi_test_1',
            ]],
        ], JSON_THROW_ON_ERROR);

        $evenement = $this->gateway->verifyWebhook($corps, $this->gateway->signPayload($corps, 1_700_000_000));

        return $this->handler->handle($evenement, new DateTimeImmutable(self::MAINTENANT));
    }

    private function creerCommande(): \App\Repository\PersistedOrder
    {
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, $this->artwork, 1)
            ->add(LineKind::Reproduction, $this->variant, 2);

        $valorisation = PricingPolicy::value($panier, $this->catalogue());

        $ventilation = VatPolicy::apply(
            VatMode::Exempt293b,
            new VatRateTable(
                new VatRate(VatCategory::OriginalArtwork, 550, new DateTimeImmutable('2025-01-01'), null),
                new VatRate(VatCategory::StandardGoods, 2000, new DateTimeImmutable('2014-01-01'), null),
            ),
            new DateTimeImmutable(self::MAINTENANT),
            $valorisation->taxableLines(),
            Money::zero(),
        );

        $commande = $this->orders->create(
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
            ),
            new DateTimeImmutable(self::MAINTENANT),
        );

        // Le tunnel reserve l'œuvre et rattache la session juste apres.
        (new StockRepository($this->pdo))->reserve(
            $this->artwork,
            new DateTimeImmutable('2026-07-22 10:30:00'),
            new DateTimeImmutable(self::MAINTENANT),
        );
        $this->orders->attachCheckoutSession($commande->id, 'cs_test_' . $commande->id, '2026-07-22 10:30:00');

        return $commande;
    }

    private function catalogue(): ItemCatalogue
    {
        return new ItemCatalogue(
            new PurchasableItem(
                kind: LineKind::Original,
                targetId: $this->artwork,
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
                targetId: $this->variant,
                label: 'Tirage — 30 × 40 cm',
                sku: 'ART-3040',
                unitPrice: Money::fromCents(6000),
                vatCategory: VatCategory::StandardGoods,
                weightGrams: 300,
                isSellable: true,
                stockQty: 10,
                editionsRemaining: 30,
            ),
        );
    }

    private function creerCatalogue(): void
    {
        $this->pdo->exec(
            'INSERT INTO categories (position, is_published, created_at, updated_at) VALUES (0, 1, NOW(), NOW())'
        );
        $category = (int) $this->pdo->lastInsertId();

        $artwork = $this->pdo->prepare(
            'INSERT INTO artworks (category_id, reference, status, price_cents, weight_grams, is_published,
                                   created_at, updated_at)
             VALUES (:cat, :ref, :status, 45000, 800, 1, NOW(), NOW())'
        );
        $artwork->execute([
            'cat' => $category,
            'ref' => 'REF-' . bin2hex(random_bytes(6)),
            'status' => 'available',
        ]);
        $this->artwork = (int) $this->pdo->lastInsertId();

        $product = $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, edition_size, is_published, created_at, updated_at)
             VALUES (:art, :kind, 30, 1, NOW(), NOW())'
        );
        $product->execute(['art' => $this->artwork, 'kind' => 'limited']);
        $this->product = (int) $this->pdo->lastInsertId();

        $variant = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           created_at, updated_at)
             VALUES (:prod, :sku, :size, 6000, 10, 300, NOW(), NOW())'
        );
        $variant->execute([
            'prod' => $this->product,
            'sku' => 'ART-3040',
            'size' => '30 × 40 cm',
        ]);
        $this->variant = (int) $this->pdo->lastInsertId();
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return null;
        }

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
