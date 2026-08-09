<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Locale;
use App\Domain\Order\Address;
use App\Domain\Shipping\ShippingMethod;
use App\Domain\Shop\LineKind;
use App\Repository\CartRepository;
use App\Repository\FulfillmentRepository;
use App\Repository\OrderRepository;
use App\Repository\ShippingRepository;
use App\Repository\StockRepository;
use App\Repository\VatRepository;
use App\Service\Fulfillment\Exception\ProdigiException;
use App\Service\Fulfillment\FakeProdigiClient;
use App\Service\Fulfillment\ProdigiConfig;
use App\Service\Fulfillment\ReproductionShipping;
use App\Service\Payment\CheckoutOutcome;
use App\Service\Payment\CheckoutRequest;
use App\Service\Payment\CheckoutService;
use App\Service\Payment\FakeGateway;
use App\Service\Payment\ShippingPricer;
use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\RecordingLogger;

/**
 * Creation de commande et de session de paiement (03-boutique §3, etape 2).
 *
 * Le point ou le site refuse de faire confiance au client : le panier est
 * REVALIDE, les montants RECALCULES depuis la base, et c'est ce recalcul —
 * jamais ce que le navigateur a envoye — qui part vers Stripe (§8.1 et §8.2).
 *
 * Tout se joue dans UNE transaction : commande, lignes figees et reservations.
 * Si une reservation echoue, rien ne subsiste — sans quoi une œuvre resterait
 * bloquee au profit d'une commande qui n'existe pas.
 */
final class CheckoutServiceTest extends DatabaseTestCase
{
    private const MAINTENANT = '2026-07-22 10:00:00';

    /** Forfait de secours du port des reproductions, par copie (centimes). */
    private const FORFAIT_REPRO = 790;

    private CheckoutService $service;
    private FakeGateway $gateway;
    private CartRepository $carts;
    private FakeProdigiClient $prodigi;

    private int $artwork;
    private int $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway('whsec_test');
        $this->carts = new CartRepository($this->pdo);

        // Prodigi configuré (clé sandbox) : les reproductions sont chiffrées par
        // devis. Par défaut le devis répond 495 centimes en EUR ; chaque test le
        // règle à sa guise (respondQuoteWith / failQuoteWith).
        $this->prodigi = new FakeProdigiClient();
        $this->prodigi->respondQuoteWith(495, 'EUR');

        $reproductions = new ReproductionShipping(
            $this->prodigi,
            ProdigiConfig::resolve('sandbox', 'preprod', ['sandboxKey' => 'sk-sandbox', 'liveKey' => '']),
            new FulfillmentRepository($this->pdo),
            new RecordingLogger(),
            self::FORFAIT_REPRO,
        );

        $this->service = new CheckoutService(
            $this->pdo,
            $this->carts,
            new OrderRepository($this->pdo),
            new StockRepository($this->pdo),
            new VatRepository($this->pdo),
            new ShippingPricer(new ShippingRepository($this->pdo), $reproductions),
            $this->gateway,
            self::urlGenerator(),
            new RecordingLogger(),
        );

        $this->creerCatalogue();
    }

    // ----------------------------------------------------------- cas nominal

    public function test_un_panier_valide_cree_une_commande_en_attente(): void
    {
        $resultat = $this->commander();

        $this->assertSame(CheckoutOutcome::Redirect, $resultat->outcome);
        $this->assertNotNull($resultat->order);
        $this->assertSame('CT-2026-0001', $resultat->order->reference);
        $this->assertSame('pending', $this->valeur('SELECT status FROM orders'));
    }

    public function test_les_montants_sont_recalcules_depuis_la_base(): void
    {
        // 03-boutique §8.1 : « le client ne transmet jamais de prix ». Le
        // panier ne porte qu'un identifiant et une quantite.
        $resultat = $this->commander();

        $this->assertNotNull($resultat->order);
        // Original 45 000 + deux tirages a 6 000 = 57 000 centimes. Le sous-total
        // depasse le franco France (30 000) : la part ATELIER (l'original) est
        // offerte. La part PRODIGI (les tirages) reste due — le franco de
        // l'artiste ne couvre pas l'expedition de l'imprimeur : 495 de devis.
        $this->assertSame(57000, $resultat->order->subtotal->cents);
        $this->assertSame(495, $resultat->order->shipping->cents);
        $this->assertSame(57495, $resultat->order->total->cents);
    }

    public function test_le_port_d_une_reproduction_vient_du_devis_prodigi(): void
    {
        // Une reproduction est imprimee et expediee par Prodigi : son port suit
        // le devis en direct, pas le bareme au poids de l'atelier.
        $this->prodigi->respondQuoteWith(650, 'EUR');

        $resultat = $this->commanderPanier([[LineKind::Reproduction, $this->variant, 1]]);

        $this->assertNotNull($resultat->order);
        $this->assertSame(6000, $resultat->order->subtotal->cents);
        $this->assertSame(650, $resultat->order->shipping->cents);
        $this->assertSame(6650, $resultat->order->total->cents);
    }

    public function test_un_devis_prodigi_en_panne_retombe_sur_le_forfait(): void
    {
        // Prodigi injoignable : on ne perd pas la vente et on n'expedie pas
        // gratis — le forfait de secours (par copie) prend le relais.
        $this->prodigi->failQuoteWith(new ProdigiException('panne'));

        $resultat = $this->commanderPanier([[LineKind::Reproduction, $this->variant, 2]]);

        $this->assertNotNull($resultat->order);
        $this->assertSame(2 * self::FORFAIT_REPRO, $resultat->order->shipping->cents);
    }

    public function test_un_panier_mixte_additionne_atelier_et_prodigi(): void
    {
        // Sous le franco : la part atelier (original, 900) s'ajoute au devis
        // Prodigi (500) des reproductions.
        $this->pdo->exec("UPDATE artworks SET price_cents = 20000 WHERE id = {$this->artwork}");
        $this->prodigi->respondQuoteWith(500, 'EUR');

        $resultat = $this->commanderPanier(
            [[LineKind::Original, $this->artwork, 1], [LineKind::Reproduction, $this->variant, 1]],
        );

        $this->assertNotNull($resultat->order);
        // 20 000 + 6 000 = 26 000 (< franco 30 000) : atelier 900 + Prodigi 500.
        $this->assertSame(1400, $resultat->order->shipping->cents);
    }

    public function test_un_prix_modifie_en_back_office_s_applique_immediatement(): void
    {
        $this->pdo->exec("UPDATE artworks SET price_cents = 52000 WHERE id = {$this->artwork}");

        $resultat = $this->commander();

        $this->assertNotNull($resultat->order);
        $this->assertSame(64000, $resultat->order->subtotal->cents);
    }

    public function test_le_total_envoye_a_stripe_est_celui_de_la_commande(): void
    {
        // 03-boutique §8.2, le cœur de PriceIntegrityTest.
        $resultat = $this->commander();

        $demande = $this->gateway->lastCheckout();

        $this->assertNotNull($demande);
        $this->assertNotNull($resultat->order);
        $this->assertSame($resultat->order->total->cents, $demande['total']);
        $this->assertSame($resultat->order->reference, $demande['reference']);
    }

    public function test_la_session_stripe_est_rattachee_a_la_commande(): void
    {
        $resultat = $this->commander();

        $this->assertNotNull($resultat->order);
        $this->assertNotNull($resultat->redirectUrl);
        $this->assertNotNull($this->valeur('SELECT stripe_session_id FROM orders'));
    }

    public function test_l_oeuvre_originale_est_reservee_trente_minutes(): void
    {
        // 03-boutique §3 : « passage available vers reserved avec
        // reserved_until = maintenant + 30 minutes ».
        $this->commander();

        $this->assertSame('reserved', $this->valeur("SELECT status FROM artworks WHERE id = {$this->artwork}"));
        $this->assertSame(
            '2026-07-22 10:30:00',
            $this->valeur("SELECT reserved_until FROM artworks WHERE id = {$this->artwork}"),
        );
    }

    public function test_la_session_stripe_expire_avec_la_reservation(): void
    {
        // 03-boutique §6 : « expires_at de la session aligne sur
        // reserved_until ». Sinon un client paie une piece deja reliberee.
        $this->commander();

        $demande = $this->gateway->lastCheckout();

        $this->assertNotNull($demande);
        $this->assertSame(
            (new DateTimeImmutable('2026-07-22 10:30:00'))->getTimestamp(),
            $demande['expires_at'],
        );
    }

    public function test_le_stock_n_est_pas_decremente_a_la_commande(): void
    {
        // 03-boutique §8.3 : le decrement n'a lieu QUE dans le webhook. Le
        // faire ici viderait le stock a chaque panier abandonne.
        $this->commander();

        $this->assertSame(
            10,
            (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$this->variant}"),
        );
    }

    // -------------------------------------------------- revalidation du panier

    public function test_une_oeuvre_vendue_entre_temps_interrompt_la_commande(): void
    {
        // 03-boutique §3 : « la transaction est annulee et l'utilisateur
        // revient au panier avec un message clair ».
        $this->pdo->exec("UPDATE artworks SET status = 'sold' WHERE id = {$this->artwork}");

        $resultat = $this->commander();

        $this->assertSame(CheckoutOutcome::CartChanged, $resultat->outcome);
        $this->assertNotSame([], $resultat->notices);
        $this->assertSame(0, $this->compter('orders'));
    }

    public function test_une_oeuvre_reservee_par_un_autre_interrompt_la_commande(): void
    {
        // Deux acheteurs au meme instant : le second doit repartir sans
        // commande ET sans reservation.
        (new StockRepository($this->pdo))->reserve(
            $this->artwork,
            new DateTimeImmutable('2026-07-22 10:25:00'),
            new DateTimeImmutable(self::MAINTENANT),
        );

        $resultat = $this->commander();

        $this->assertSame(CheckoutOutcome::CartChanged, $resultat->outcome);
        $this->assertSame(0, $this->compter('orders'));
    }

    public function test_une_commande_interrompue_ne_laisse_rien_derriere_elle(): void
    {
        // Les tests ci-dessus s'arretent AVANT la creation de la commande, a la
        // revalidation. Celui-ci verifie le contrat d'annulation lui-meme, avec
        // le service maitre de sa transaction — sans quoi on ne saurait pas si
        // le rollback existe vraiment ou si la revalidation le rendait inutile.
        // L'annulation emporte le catalogue cree par setUp() : il faut le
        // reconstruire hors transaction avant d'agir.
        $this->pdo->rollBack();
        $this->creerCatalogue();
        $this->pdo->exec("UPDATE artworks SET status = 'sold' WHERE id = {$this->artwork}");

        $resultat = $this->commander();

        $this->assertSame(CheckoutOutcome::CartChanged, $resultat->outcome);
        $this->assertSame(0, $this->compter('orders'));
        $this->assertSame(0, $this->compter('order_items'));

        $this->nettoyer();
    }

    public function test_la_reservation_reste_impossible_si_l_oeuvre_part_entre_temps(): void
    {
        // La divergence entre « le catalogue dit disponible » et « le verrou
        // refuse » n'est atteignable que sous concurrence reelle : les deux
        // consultent la MEME regle de disponibilite, et ne peuvent differer que
        // dans le temps qui les separe.
        //
        // Ce test le documente en verifiant l'autre bout de la chaine : une
        // œuvre reservee par un tiers avant l'appel ne donne ni commande ni
        // reservation au second acheteur. Le garde-fou du service reste une
        // ceinture pour le cas ou les deux instants divergent.
        $seconde = self::secondeConnexion();
        $this->pdo->rollBack();
        $this->creerCatalogue();

        (new StockRepository($seconde))->reserve(
            $this->artwork,
            new DateTimeImmutable('2026-07-22 10:25:00'),
            new DateTimeImmutable(self::MAINTENANT),
        );

        $resultat = $this->commander();

        $this->assertSame(CheckoutOutcome::CartChanged, $resultat->outcome);
        $this->assertSame(0, $this->compter('orders'));
        $this->assertSame(
            '2026-07-22 10:25:00',
            $this->valeur("SELECT reserved_until FROM artworks WHERE id = {$this->artwork}"),
            'La réservation du premier acheteur ne doit pas avoir été écrasée.',
        );

        $this->nettoyer();
    }

    public function test_un_stock_devenu_insuffisant_interrompt_la_commande(): void
    {
        $this->pdo->exec("UPDATE product_variants SET stock_qty = 1 WHERE id = {$this->variant}");

        $resultat = $this->commander();

        $this->assertSame(CheckoutOutcome::CartChanged, $resultat->outcome);
        $this->assertSame(0, $this->compter('orders'));
    }

    public function test_un_panier_vide_interrompt_la_commande(): void
    {
        $panier = $this->carts->open(null, Locale::Fr);

        $resultat = $this->service->checkout($panier, $this->demande(), new DateTimeImmutable(self::MAINTENANT));

        $this->assertSame(CheckoutOutcome::EmptyCart, $resultat->outcome);
        $this->assertSame(0, $this->compter('orders'));
    }

    // ------------------------------------------------------------ expedition

    public function test_un_colis_hors_bareme_demande_un_devis(): void
    {
        // 03-boutique §4 : « le site affiche "devis d'expedition sur demande" ».
        $this->pdo->exec("UPDATE artworks SET weight_grams = 40000 WHERE id = {$this->artwork}");

        $resultat = $this->commander();

        $this->assertSame(CheckoutOutcome::ShippingOnRequest, $resultat->outcome);
        $this->assertSame(0, $this->compter('orders'));
    }

    public function test_un_poids_indetermine_demande_un_devis(): void
    {
        $this->pdo->exec("UPDATE artworks SET weight_grams = NULL WHERE id = {$this->artwork}");

        $resultat = $this->commander();

        $this->assertSame(CheckoutOutcome::ShippingOnRequest, $resultat->outcome);
    }

    public function test_la_remise_en_main_propre_ignore_le_poids_et_ne_coute_rien(): void
    {
        // Panier d'originaux seuls : le retrait reste possible et gratuit, quel
        // que soit le poids.
        $this->pdo->exec("UPDATE artworks SET weight_grams = 40000 WHERE id = {$this->artwork}");

        $resultat = $this->commanderPanier(
            [[LineKind::Original, $this->artwork, 1]],
            $this->demande(mode: ShippingMethod::Pickup),
        );

        $this->assertSame(CheckoutOutcome::Redirect, $resultat->outcome);
        $this->assertNotNull($resultat->order);
        $this->assertSame(0, $resultat->order->shipping->cents);
        $this->assertNull($this->valeur('SELECT shipping_address FROM orders'));
    }

    public function test_le_retrait_est_refuse_quand_le_panier_contient_une_reproduction(): void
    {
        // Une reproduction est expediee par Prodigi : elle ne peut pas etre
        // remise en main propre. Le tunnel renvoie « sur devis » plutot que de
        // creer une commande qui ne pourrait pas etre honoree.
        $resultat = $this->commanderPanier(
            [[LineKind::Reproduction, $this->variant, 1]],
            $this->demande(mode: ShippingMethod::Pickup),
        );

        $this->assertSame(CheckoutOutcome::ShippingOnRequest, $resultat->outcome);
        $this->assertSame(0, $this->compter('orders'));
    }

    public function test_le_franco_bascule_au_centime_pres(): void
    {
        // Seuil France a 30 000 centimes, sur la part ATELIER (les originaux).
        // Un original a 29 999 reste facture, a 30 000 le port tombe : c'est la
        // borne exacte qui compte, une comparaison stricte de trop la deplacerait.
        $this->pdo->exec("UPDATE artworks SET price_cents = 29999 WHERE id = {$this->artwork}");
        $sous = $this->commanderPanier([[LineKind::Original, $this->artwork, 1]]);

        // L'original a ete reserve : on le libere pour pouvoir le recommander.
        $this->pdo->exec(
            "UPDATE artworks SET status = 'available', reserved_until = NULL, price_cents = 30000"
            . " WHERE id = {$this->artwork}"
        );
        $atteint = $this->commanderPanier([[LineKind::Original, $this->artwork, 1]]);

        $this->assertNotNull($sous->order);
        $this->assertNotNull($atteint->order);
        $this->assertSame(900, $sous->order->shipping->cents);
        $this->assertSame(0, $atteint->order->shipping->cents);
    }

    public function test_une_edition_limitee_paie_le_port_atelier_pas_prodigi(): void
    {
        // Édition limitée = traitement atelier : port au barème poids (comme un
        // original), jamais le devis/forfait Prodigi. Aucun devis n'est demandé.
        $variante = $this->creerEditionLimitee(prix: 25000);

        $resultat = $this->commanderPanier([[LineKind::Reproduction, $variante, 1]]);

        $this->assertNotNull($resultat->order);
        // France ≤ 10 kg, sous le franco : 900 (barème atelier), pas 790 (forfait).
        $this->assertSame(900, $resultat->order->shipping->cents);
        $this->assertSame([], $this->prodigi->quotes, 'Aucun devis Prodigi pour une ligne atelier.');
    }

    public function test_le_retrait_est_possible_pour_une_edition_limitee(): void
    {
        // Rehaussée à l'atelier d'Amiens : elle peut être remise en main propre,
        // contrairement à un tirage Fine Art expédié par le prestataire.
        $variante = $this->creerEditionLimitee();

        $resultat = $this->commanderPanier(
            [[LineKind::Reproduction, $variante, 1]],
            $this->demande(mode: ShippingMethod::Pickup),
        );

        $this->assertSame(CheckoutOutcome::Redirect, $resultat->outcome);
        $this->assertNotNull($resultat->order);
        $this->assertSame(0, $resultat->order->shipping->cents);
    }

    // ------------------------------------------------------------------- TVA

    public function test_en_franchise_la_commande_porte_une_tva_nulle(): void
    {
        $resultat = $this->commander();

        $this->assertNotNull($resultat->order);
        $this->assertSame(0, $resultat->order->vat->cents);
        $this->assertSame('exempt_293b', $this->valeur('SELECT vat_mode FROM orders'));
    }

    public function test_apres_bascule_la_commande_est_taxee(): void
    {
        $this->pdo->exec(
            'UPDATE settings SET value = \'{"mode":"taxed","taxable_from":"2026-01-01"}\' WHERE `key` = \'vat\''
        );

        $resultat = $this->commander();

        $this->assertNotNull($resultat->order);
        $this->assertGreaterThan(0, $resultat->order->vat->cents);
        $this->assertSame('taxed', $this->valeur('SELECT vat_mode FROM orders'));
    }

    // ------------------------------------------------------------ assistance

    private function commander(?CheckoutRequest $demande = null): \App\Service\Payment\CheckoutResult
    {
        return $this->commanderPanier(
            [[LineKind::Original, $this->artwork, 1], [LineKind::Reproduction, $this->variant, 2]],
            $demande,
        );
    }

    /**
     * @param list<array{LineKind, int, int}> $lignes
     */
    private function commanderPanier(array $lignes, ?CheckoutRequest $demande = null): \App\Service\Payment\CheckoutResult
    {
        $panier = $this->carts->open(null, Locale::Fr);

        foreach ($lignes as [$genre, $cible, $quantite]) {
            $panier = $panier->add($genre, $cible, $quantite);
        }

        $this->carts->save($panier);

        return $this->service->checkout(
            $panier,
            $demande ?? $this->demande(),
            new DateTimeImmutable(self::MAINTENANT),
        );
    }

    private function demande(ShippingMethod $mode = ShippingMethod::Shipping): CheckoutRequest
    {
        return new CheckoutRequest(
            locale: Locale::Fr,
            customerName: 'Acheteur',
            customerEmail: 'acheteur@example.test',
            customerPhone: null,
            shippingMethod: $mode,
            shippingAddress: new Address('12 rue des Trois-Cailloux', null, '80000', 'Amiens', 'FR'),
            billingAddress: null,
            customerNote: null,
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

        $this->pdo->prepare(
            'INSERT INTO artwork_translations (artwork_id, locale, slug, title) VALUES (:id, :l, :s, :t)'
        )->execute(['id' => $this->artwork, 'l' => 'fr', 's' => 'articulation', 't' => 'Articulation']);

        $product = $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, is_published, created_at, updated_at)
             VALUES (:art, :kind, 1, NOW(), NOW())'
        );
        $product->execute(['art' => $this->artwork, 'kind' => 'standard']);
        $productId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :l, :t)'
        )->execute(['id' => $productId, 'l' => 'fr', 't' => 'Tirage d’art']);

        $variant = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, prodigi_sku, size_label, price_cents, stock_qty,
                                           weight_grams, created_at, updated_at)
             VALUES (:prod, :sku, :psku, :size, 6000, 10, 300, NOW(), NOW())'
        );
        $variant->execute([
            'prod' => $productId,
            'sku' => 'ART-3040',
            'psku' => 'GLOBAL-HGE-16X20',
            'size' => '30 × 40 cm',
        ]);
        $this->variant = (int) $this->pdo->lastInsertId();
    }

    /**
     * Édition limitée (circuit atelier) rattachée à l'œuvre ; renvoie l'id de sa
     * variante numérotable.
     */
    private function creerEditionLimitee(int $prix = 25000, int $editionSize = 20): int
    {
        $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, processing_mode, edition_size, is_published,
                                   created_at, updated_at)
             VALUES (:art, :kind, :mode, :size, 1, NOW(), NOW())'
        )->execute(['art' => $this->artwork, 'kind' => 'limited', 'mode' => 'artist_manual', 'size' => $editionSize]);
        $product = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :l, :t)'
        )->execute(['id' => $product, 'l' => 'fr', 't' => 'Édition limitée']);

        $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           created_at, updated_at)
             VALUES (:prod, :sku, :label, :price, :stock, 600, NOW(), NOW())'
        )->execute([
            'prod' => $product,
            'sku' => 'EL-' . bin2hex(random_bytes(4)),
            'label' => '40 × 50 cm',
            'price' => $prix,
            'stock' => $editionSize,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private static function urlGenerator(): \App\Service\I18n\UrlGenerator
    {
        $racine = dirname(__DIR__, 2);
        /** @var list<\App\Core\Route> $routes */
        $routes = require $racine . '/config/routes.php';

        return new \App\Service\I18n\UrlGenerator(
            new \App\Core\Router($routes),
            \App\Core\Config::fromEnv(\App\Core\Env::fromArray([
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
    }

    private static function secondeConnexion(): \PDO
    {
        $pdo = (new \App\Core\Database(
            host: getenv('DB_TEST_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('DB_TEST_PORT') ?: (getenv('DB_PORT') ?: '13306')),
            name: getenv('DB_TEST_NAME') ?: 'cedrictaldu_test',
            user: getenv('DB_MIGRATION_USER') ?: 'cedrictaldu_migrate',
            password: getenv('DB_MIGRATION_PASSWORD') ?: 'migration',
        ))->connect();

        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

        return $pdo;
    }

    /**
     * Efface ce qu'un test sorti de la transaction a commite.
     *
     * L'ordre importe : artworks porte un ON DELETE RESTRICT vers categories.
     */
    private function nettoyer(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->pdo->exec('DELETE FROM order_items');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM cart_items');
        $this->pdo->exec('DELETE FROM carts');
        $this->pdo->exec('DELETE FROM artworks');
        $this->pdo->exec('DELETE FROM categories');
    }

    private function compter(string $table): int
    {
        return (int) $this->valeur("SELECT COUNT(*) FROM `{$table}`");
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
