<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Exception\InvalidOrderTransition;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\Address;
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
use App\Repository\OrderRepository;
use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;

/**
 * Creation et cycle de vie d'une commande.
 *
 * Tout ce qui est ecrit ici est FIGE : aucun traitement ulterieur ne recalcule
 * les montants d'une commande existante (01-modele §7.7 et §7.9). Les tests
 * verifient donc autant ce qui est ecrit que ce qui NE BOUGE PLUS ensuite.
 */
final class OrderRepositoryTest extends DatabaseTestCase
{
    private const MAINTENANT = '2026-07-22 10:00:00';

    private OrderRepository $repository;
    private int $artwork;
    private int $variant;
    private int $category = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new OrderRepository($this->pdo);
        $this->artwork = $this->creerOeuvre();
        $this->variant = $this->creerVariante();
    }

    /**
     * Efface ce qu'un test sorti de la transaction a commite.
     *
     * L'ordre importe : les commandes referencent les œuvres en ON DELETE SET
     * NULL, mais artworks porte un ON DELETE RESTRICT vers categories.
     */
    private function nettoyer(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->pdo->exec('DELETE FROM order_items');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec("DELETE FROM artworks WHERE category_id = {$this->category}");
        $this->pdo->exec("DELETE FROM categories WHERE id = {$this->category}");
    }

    // ------------------------------------------------------------- reference

    public function test_la_premiere_commande_de_l_annee_porte_le_numero_un(): void
    {
        $commande = $this->creerCommande();

        $this->assertSame('CT-2026-0001', $commande->reference);
    }

    public function test_les_references_se_suivent(): void
    {
        $this->creerCommande();
        $this->creerCommande();

        $this->assertSame('CT-2026-0003', $this->creerCommande()->reference);
    }

    public function test_le_compteur_repart_a_un_au_changement_d_annee(): void
    {
        $this->creerCommande();

        $commande = $this->creerCommande(maintenant: '2027-01-02 09:00:00');

        $this->assertSame('CT-2027-0001', $commande->reference);
    }

    public function test_une_reference_d_une_autre_annee_n_influence_pas_le_compteur(): void
    {
        // Le compteur porte sur l'annee de la commande, pas sur le maximum
        // absolu de la table.
        $this->creerCommande(maintenant: '2025-06-01 09:00:00');
        $this->creerCommande(maintenant: '2025-06-02 09:00:00');

        $this->assertSame('CT-2026-0001', $this->creerCommande()->reference);
    }

    // ------------------------------------------------------------- ecriture

    public function test_une_commande_nait_en_attente_de_paiement(): void
    {
        // 03-boutique §3 : la commande est creee en `pending`. Le statut `paid`
        // n'est atteignable que par le webhook signe (§8.3).
        $commande = $this->creerCommande();

        $this->assertSame(OrderStatus::Pending, $commande->status);
        $this->assertSame('pending', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertNull($this->valeur("SELECT paid_at FROM orders WHERE id = {$commande->id}"));
    }

    public function test_les_montants_de_la_commande_sont_ecrits(): void
    {
        $commande = $this->creerCommande();

        $this->assertSame(57000, (int) $this->valeur("SELECT subtotal_cents FROM orders WHERE id = {$commande->id}"));
        $this->assertSame(900, (int) $this->valeur("SELECT shipping_cents FROM orders WHERE id = {$commande->id}"));
        $this->assertSame(0, (int) $this->valeur("SELECT vat_cents FROM orders WHERE id = {$commande->id}"));
        $this->assertSame(57900, (int) $this->valeur("SELECT total_cents FROM orders WHERE id = {$commande->id}"));
        $this->assertSame('exempt_293b', $this->valeur("SELECT vat_mode FROM orders WHERE id = {$commande->id}"));
    }

    public function test_chaque_ligne_est_figee_avec_son_libelle_et_son_prix(): void
    {
        $commande = $this->creerCommande();

        $lignes = $this->lignes($commande->id);

        $this->assertCount(2, $lignes);
        $this->assertSame('Articulation', $lignes[0]['label']);
        $this->assertSame(45000, (int) $lignes[0]['unit_price_cents']);
        $this->assertSame('original_artwork', $lignes[0]['vat_category']);
        $this->assertSame('ART-3040', $lignes[1]['sku']);
        $this->assertSame(2, (int) $lignes[1]['qty']);
    }

    public function test_les_quote_parts_de_port_somment_au_port_de_la_commande(): void
    {
        // 01-modele §7.6, verifie sur ce qui est REELLEMENT en base.
        $commande = $this->creerCommande();

        $this->assertSame(
            900,
            (int) $this->valeur(
                "SELECT SUM(shipping_share_cents) FROM order_items WHERE order_id = {$commande->id}"
            ),
        );
    }

    public function test_le_total_des_lignes_egale_le_sous_total(): void
    {
        $commande = $this->creerCommande();

        $this->assertSame(
            57000,
            (int) $this->valeur("SELECT SUM(total_cents) FROM order_items WHERE order_id = {$commande->id}"),
        );
    }

    public function test_le_jeton_de_consultation_fait_soixante_quatre_caracteres(): void
    {
        // 06-securite §8 : jetons publics aleatoires sur 32 octets.
        $commande = $this->creerCommande();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $commande->accessToken);
    }

    public function test_deux_commandes_ont_des_jetons_differents(): void
    {
        $this->assertNotSame($this->creerCommande()->accessToken, $this->creerCommande()->accessToken);
    }

    public function test_l_adresse_de_livraison_est_enregistree(): void
    {
        $commande = $this->creerCommande();

        $adresse = json_decode(
            (string) $this->valeur("SELECT shipping_address FROM orders WHERE id = {$commande->id}"),
            true,
        );

        $this->assertIsArray($adresse);
        $this->assertSame('Amiens', $adresse['city']);
        $this->assertSame('FR', $adresse['country']);
    }

    public function test_une_remise_en_main_propre_n_enregistre_aucune_adresse(): void
    {
        // 06-securite §9 : pas de donnee personnelle sans finalite.
        $commande = $this->creerCommande(mode: ShippingMethod::Pickup, port: 0);

        $this->assertNull($this->valeur("SELECT shipping_address FROM orders WHERE id = {$commande->id}"));
        $this->assertSame('pickup', $this->valeur("SELECT shipping_method FROM orders WHERE id = {$commande->id}"));
    }

    public function test_une_creation_qui_echoue_ne_laisse_aucune_commande_partielle(): void
    {
        // La commande et ses lignes sont ecrites dans UNE transaction : une
        // commande sans ligne serait un total sans contenu.
        //
        // Ce test doit s'executer HORS de la transaction de DatabaseTestCase.
        // Quand l'appelant possede deja une transaction, le depot ne l'annule
        // pas — c'est a l'appelant de le faire, et c'est bien le comportement
        // voulu : le tunnel enveloppe la creation ET les reservations dans une
        // seule transaction. Rester dans la transaction du test verifierait le
        // contraire de la promesse.
        $brouillon = $this->brouillon();
        $this->pdo->rollBack();

        $this->pdo->exec("DELETE FROM product_variants WHERE id = {$this->variant}");

        try {
            $this->repository->create($brouillon, new DateTimeImmutable(self::MAINTENANT));
            $this->fail('La creation aurait du echouer sur la variante disparue.');
        } catch (\PDOException) {
            // attendu
        }

        $this->assertSame(0, $this->compter('orders'));
        $this->assertSame(0, $this->compter('order_items'));

        $this->nettoyer();
    }

    public function test_une_creation_qui_echoue_laisse_l_appelant_annuler_sa_transaction(): void
    {
        // L'autre moitie de la promesse : quand le tunnel possede deja la
        // transaction, le depot propage l'exception sans annuler, et
        // l'annulation de l'appelant emporte tout.
        $brouillon = $this->brouillon();
        $this->pdo->exec("DELETE FROM product_variants WHERE id = {$this->variant}");

        try {
            $this->repository->create($brouillon, new DateTimeImmutable(self::MAINTENANT));
            $this->fail('La creation aurait du echouer sur la variante disparue.');
        } catch (\PDOException) {
            // attendu
        }

        $this->pdo->rollBack();

        $this->assertSame(0, $this->compter('orders'));
        $this->assertSame(0, $this->compter('order_items'));
    }

    // ------------------------------------------------------------- lecture

    public function test_une_commande_se_relit_par_sa_reference_et_son_jeton(): void
    {
        $creee = $this->creerCommande();

        $relue = $this->repository->findByReferenceAndToken($creee->reference, $creee->accessToken);

        $this->assertNotNull($relue);
        $this->assertSame($creee->id, $relue->id);
        $this->assertSame(57900, $relue->total->cents);
        $this->assertCount(2, $relue->lines);
    }

    public function test_un_jeton_faux_ne_donne_acces_a_rien(): void
    {
        // 03-boutique §3 : « aucune information de commande n'est accessible
        // sans lui ». C'est la seule protection de la page de confirmation.
        $creee = $this->creerCommande();

        $this->assertNull(
            $this->repository->findByReferenceAndToken($creee->reference, str_repeat('0', 64)),
        );
    }

    public function test_un_jeton_vide_ne_donne_acces_a_rien(): void
    {
        $creee = $this->creerCommande();

        $this->assertNull($this->repository->findByReferenceAndToken($creee->reference, ''));
    }

    public function test_une_reference_mal_formee_ne_touche_pas_la_base(): void
    {
        $creee = $this->creerCommande();

        foreach (["CT-2026-0001' OR '1'='1", '../../etc/passwd', "CT-2026-0001\0", ''] as $reference) {
            $this->assertNull($this->repository->findByReferenceAndToken($reference, $creee->accessToken));
        }
    }

    public function test_une_commande_se_retrouve_par_sa_session_stripe(): void
    {
        // Le webhook n'a que le client_reference_id et l'identifiant de session.
        $creee = $this->creerCommande();
        $this->repository->attachCheckoutSession($creee->id, 'cs_test_123', '2026-07-22 10:30:00');

        $relue = $this->repository->findByStripeSession('cs_test_123');

        $this->assertNotNull($relue);
        $this->assertSame($creee->id, $relue->id);
    }

    public function test_une_session_stripe_inconnue_ne_rend_rien(): void
    {
        $this->assertNull($this->repository->findByStripeSession('cs_test_inconnue'));
    }

    // --------------------------------------------------------- transitions

    public function test_une_commande_passe_a_payee(): void
    {
        $commande = $this->creerCommande();

        $this->assertTrue($this->repository->transitionTo(
            $commande->id,
            OrderStatus::Pending,
            OrderStatus::Paid,
            new DateTimeImmutable('2026-07-22 10:05:00'),
        ));

        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame(
            '2026-07-22 10:05:00',
            $this->valeur("SELECT paid_at FROM orders WHERE id = {$commande->id}"),
        );
    }

    public function test_une_transition_depuis_un_statut_perime_echoue(): void
    {
        // C'est le garde-fou du rejeu de webhook : le statut ATTENDU voyage
        // dans le WHERE. Deux livraisons du meme evenement ne peuvent pas
        // appliquer l'effet deux fois.
        $commande = $this->creerCommande();
        $this->repository->transitionTo(
            $commande->id,
            OrderStatus::Pending,
            OrderStatus::Paid,
            new DateTimeImmutable(self::MAINTENANT),
        );

        $this->assertFalse($this->repository->transitionTo(
            $commande->id,
            OrderStatus::Pending,
            OrderStatus::Paid,
            new DateTimeImmutable(self::MAINTENANT),
        ));
    }

    public function test_une_transition_interdite_leve_une_exception(): void
    {
        // 03-boutique §8.4 : la machine a etats est consultee AVANT d'ecrire.
        // Une commande en attente ne s'expedie pas.
        $commande = $this->creerCommande();

        $this->expectException(InvalidOrderTransition::class);

        $this->repository->transitionTo(
            $commande->id,
            OrderStatus::Pending,
            OrderStatus::Shipped,
            new DateTimeImmutable(self::MAINTENANT),
        );
    }

    public function test_l_expedition_enregistre_le_transporteur_et_le_suivi(): void
    {
        $commande = $this->creerCommande();
        $this->repository->transitionTo(
            $commande->id,
            OrderStatus::Pending,
            OrderStatus::Paid,
            new DateTimeImmutable(self::MAINTENANT),
        );

        $this->assertTrue($this->repository->ship(
            $commande->id,
            'Colissimo',
            '6A123456789',
            new DateTimeImmutable('2026-07-25 14:00:00'),
        ));

        $this->assertSame('shipped', $this->valeur("SELECT status FROM orders WHERE id = {$commande->id}"));
        $this->assertSame(
            'Colissimo',
            $this->valeur("SELECT tracking_carrier FROM orders WHERE id = {$commande->id}"),
        );
        $this->assertSame(
            '2026-07-25 14:00:00',
            $this->valeur("SELECT shipped_at FROM orders WHERE id = {$commande->id}"),
        );
    }

    public function test_une_commande_non_payee_ne_s_expedie_pas(): void
    {
        $commande = $this->creerCommande();

        $this->expectException(InvalidOrderTransition::class);

        $this->repository->ship($commande->id, 'Colissimo', '6A1', new DateTimeImmutable(self::MAINTENANT));
    }

    // ---------------------------------------------------------- anomalies

    public function test_une_anomalie_se_consigne_sur_la_commande_et_sur_la_ligne(): void
    {
        // 03-boutique §8.5 : le second acheteur voit sa commande marquee payee
        // ET signalee pour remboursement manuel. « On ne perd jamais un
        // paiement encaisse. »
        $commande = $this->creerCommande();
        $ligne = (int) $this->valeur("SELECT id FROM order_items WHERE order_id = {$commande->id} LIMIT 1");

        $this->repository->flagAnomaly($commande->id, $ligne, 'already_sold', 'Œuvre déjà vendue');

        $this->assertSame(
            'already_sold',
            $this->valeur("SELECT anomaly FROM order_items WHERE id = {$ligne}"),
        );
        $this->assertStringContainsString(
            'déjà vendue',
            (string) $this->valeur("SELECT anomaly_note FROM orders WHERE id = {$commande->id}"),
        );
    }

    public function test_plusieurs_anomalies_s_accumulent_sans_s_ecraser(): void
    {
        // Une commande peut echouer sur deux lignes a la fois. Ecraser la note
        // ferait disparaitre la premiere anomalie du tableau de bord.
        $commande = $this->creerCommande();
        $lignes = $this->lignes($commande->id);

        $this->repository->flagAnomaly($commande->id, (int) $lignes[0]['id'], 'already_sold', 'Œuvre déjà vendue');
        $this->repository->flagAnomaly($commande->id, (int) $lignes[1]['id'], 'edition_exhausted', 'Édition épuisée');

        $note = (string) $this->valeur("SELECT anomaly_note FROM orders WHERE id = {$commande->id}");

        $this->assertStringContainsString('déjà vendue', $note);
        $this->assertStringContainsString('épuisée', $note);
    }

    public function test_un_numero_d_edition_se_consigne_sur_la_ligne(): void
    {
        $commande = $this->creerCommande();
        $lignes = $this->lignes($commande->id);

        $this->repository->setEditionNumber((int) $lignes[1]['id'], 17);

        $this->assertSame(
            17,
            (int) $this->valeur("SELECT edition_number FROM order_items WHERE id = {$lignes[1]['id']}"),
        );
    }

    // ------------------------------------------------------------ assistance

    private function creerCommande(
        string $maintenant = self::MAINTENANT,
        ShippingMethod $mode = ShippingMethod::Shipping,
        int $port = 900,
    ): \App\Repository\PersistedOrder {
        return $this->repository->create(
            $this->brouillon($mode, $port),
            new DateTimeImmutable($maintenant),
        );
    }

    private function brouillon(
        ShippingMethod $mode = ShippingMethod::Shipping,
        int $port = 900,
    ): OrderDraft {
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
            Money::fromCents($port),
        );

        return OrderDraft::fromValuation(
            valuation: $valorisation,
            vat: $ventilation,
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

    private function creerOeuvre(): int
    {
        $this->pdo->exec(
            'INSERT INTO categories (position, is_published, created_at, updated_at) VALUES (0, 1, NOW(), NOW())'
        );
        $this->category = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO artworks (category_id, reference, status, price_cents, weight_grams, is_published,
                                   created_at, updated_at)
             VALUES (:cat, :ref, :status, 45000, 800, 1, NOW(), NOW())'
        );
        $statement->execute([
            'cat' => $this->category,
            'ref' => 'REF-' . bin2hex(random_bytes(6)),
            'status' => 'available',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function creerVariante(): int
    {
        $product = $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, is_published, created_at, updated_at)
             VALUES (:art, :kind, 1, NOW(), NOW())'
        );
        $product->execute(['art' => $this->artwork, 'kind' => 'standard']);
        $productId = (int) $this->pdo->lastInsertId();

        $variant = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           created_at, updated_at)
             VALUES (:prod, :sku, :size, 6000, 10, 300, NOW(), NOW())'
        );
        $variant->execute([
            'prod' => $productId,
            'sku' => 'ART-3040',
            'size' => '30 × 40 cm',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lignes(int $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC');
        $statement->execute(['id' => $orderId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
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
