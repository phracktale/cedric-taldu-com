<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

/**
 * Le schema de la boutique porte des invariants que le code ne peut pas
 * garantir seul.
 *
 * Ils sont verifies ici parce qu'une future fonctionnalite, un script de
 * reprise ou une correction faite a la main en production ne passeront pas par
 * les depots. Ce qui protege l'argent et le stock doit tenir sans eux.
 */
final class SchemaBoutiqueTest extends DatabaseTestCase
{
    #[DataProvider('tablesDeLaBoutique')]
    public function test_la_table_est_creee(string $table): void
    {
        $this->assertContains($table, $this->tables());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tablesDeLaBoutique(): iterable
    {
        foreach (
            [
            'products',
            'product_translations',
            'product_variants',
            'carts',
            'cart_items',
            'orders',
            'order_items',
            'stripe_events',
            'vat_rates',
            'shipping_zones',
            'shipping_rates',
            ] as $table
        ) {
            yield $table => [$table];
        }
    }

    // ------------------------------------------------------------- amorcage

    public function test_les_taux_de_tva_sont_amorces(): void
    {
        // 01-modele §5 : sans cette amorce, VatPolicy leverait MissingVatRate
        // des la premiere commande en regime taxe.
        $this->assertSame(4, $this->compter('vat_rates'));

        $this->assertSame(
            550,
            (int) $this->valeur(
                "SELECT rate_bps FROM vat_rates WHERE category = 'original_artwork' AND valid_to IS NULL"
            ),
        );

        $this->assertSame(
            2000,
            (int) $this->valeur(
                "SELECT rate_bps FROM vat_rates WHERE category = 'standard_goods' AND valid_to IS NULL"
            ),
        );
    }

    public function test_l_ancien_taux_des_oeuvres_originales_est_conserve(): void
    {
        // Une facture de 2024 rejouee doit produire le meme document : la ligne
        // close a 10 % ne disparait jamais.
        $this->assertSame(
            1000,
            (int) $this->valeur(
                "SELECT rate_bps FROM vat_rates
                 WHERE category = 'original_artwork' AND valid_to = '2024-12-31'"
            ),
        );
    }

    public function test_les_trois_zones_d_expedition_sont_amorcees(): void
    {
        $this->assertSame(3, $this->compter('shipping_zones'));
        $this->assertSame(3, $this->compter('shipping_rates'));
    }

    public function test_la_grille_de_port_correspond_a_la_decision_du_21_juillet(): void
    {
        // Forfait unique par zone, tranche a 10 kg, franco FR a 300 € et UE a
        // 800 €, aucun franco hors UE.
        $this->assertSame(
            ['10000', '900', '30000'],
            $this->ligne(
                "SELECT r.max_weight_grams, r.price_cents, r.free_above_cents
                 FROM shipping_rates r JOIN shipping_zones z ON z.id = r.zone_id
                 WHERE z.code = 'FR'"
            ),
        );

        $this->assertSame(
            ['10000', '2000', '80000'],
            $this->ligne(
                "SELECT r.max_weight_grams, r.price_cents, r.free_above_cents
                 FROM shipping_rates r JOIN shipping_zones z ON z.id = r.zone_id
                 WHERE z.code = 'EU'"
            ),
        );

        $this->assertSame(
            ['10000', '3500', null],
            $this->ligne(
                "SELECT r.max_weight_grams, r.price_cents, r.free_above_cents
                 FROM shipping_rates r JOIN shipping_zones z ON z.id = r.zone_id
                 WHERE z.code = 'WORLD'"
            ),
        );
    }

    public function test_la_zone_monde_porte_le_joker(): void
    {
        $this->assertSame(
            '["*"]',
            $this->valeur("SELECT countries FROM shipping_zones WHERE code = 'WORLD'"),
        );
    }

    public function test_le_regime_de_tva_demarre_en_franchise(): void
    {
        // Decision DEFINITIVE du 2026-07-21 pour toutes les commandes de la
        // periode (01-modele §7.7).
        $reglage = json_decode((string) $this->valeur("SELECT value FROM settings WHERE `key` = 'vat'"), true);

        $this->assertIsArray($reglage);
        $this->assertSame('exempt_293b', $reglage['mode']);
        $this->assertNull($reglage['taxable_from']);
    }

    public function test_l_emballage_forfaitaire_est_amorce(): void
    {
        $reglage = json_decode((string) $this->valeur("SELECT value FROM settings WHERE `key` = 'shipping'"), true);

        $this->assertIsArray($reglage);
        $this->assertSame(250, $reglage['packaging_grams']);
    }

    // -------------------------------------------------- invariants de stock

    public function test_une_edition_ne_peut_pas_depasser_son_tirage(): void
    {
        // 01-modele §7.4. La contrainte est dans le SCHEMA, pas seulement dans
        // le code : une correction faite a la main en production echouera aussi.
        $artwork = $this->creerOeuvre();
        $product = $this->creerProduit($artwork, kind: 'limited', editionSize: 30);

        $this->expectException(PDOException::class);

        $this->pdo->exec("UPDATE products SET editions_sold = 31 WHERE id = {$product}");
    }

    public function test_une_edition_peut_atteindre_exactement_son_tirage(): void
    {
        $artwork = $this->creerOeuvre();
        $product = $this->creerProduit($artwork, kind: 'limited', editionSize: 30);

        $this->pdo->exec("UPDATE products SET editions_sold = 30 WHERE id = {$product}");

        $this->assertSame(
            30,
            (int) $this->valeur("SELECT editions_sold FROM products WHERE id = {$product}"),
        );
    }

    public function test_un_tirage_limite_exige_une_taille_d_edition(): void
    {
        $artwork = $this->creerOeuvre();

        $this->expectException(PDOException::class);

        $this->creerProduit($artwork, kind: 'limited', editionSize: null);
    }

    public function test_un_stock_ne_peut_pas_devenir_negatif(): void
    {
        // 01-modele §7.5. SMALLINT UNSIGNED interdit physiquement la valeur :
        // la contrainte de type et la contrainte applicative se couvrent l'une
        // l'autre.
        $variant = $this->creerVariante($this->creerProduit($this->creerOeuvre()), stock: 3);

        $this->expectException(PDOException::class);

        $this->pdo->exec("UPDATE product_variants SET stock_qty = stock_qty - 5 WHERE id = {$variant}");
    }

    // -------------------------------------------------- invariants monetaires

    public function test_le_total_d_une_ligne_doit_egaler_ht_plus_tva(): void
    {
        // 01-modele §7.6, pose dans le schema.
        $order = $this->creerCommande();

        $this->expectException(PDOException::class);

        $this->creerLigneDeCommande($order, total: 6000, ht: 5000, tva: 999);
    }

    public function test_la_quote_part_de_port_doit_egaler_son_ht_plus_sa_tva(): void
    {
        $order = $this->creerCommande();

        $this->expectException(PDOException::class);

        $this->creerLigneDeCommande($order, portTtc: 900, portHt: 750, portTva: 149);
    }

    public function test_une_ligne_coherente_est_acceptee(): void
    {
        $order = $this->creerCommande();

        $this->creerLigneDeCommande($order, total: 6000, ht: 5000, tva: 1000, portTtc: 900, portHt: 750, portTva: 150);

        $this->assertSame(1, $this->compter('order_items'));
    }

    // ------------------------------------------------------------- unicite

    public function test_une_reference_de_commande_est_unique(): void
    {
        $this->creerCommande(reference: 'CT-2026-0001');

        $this->expectException(PDOException::class);

        $this->creerCommande(reference: 'CT-2026-0001');
    }

    public function test_une_session_stripe_ne_sert_qu_une_commande(): void
    {
        // Deux commandes sur la meme session Stripe seraient payees par un seul
        // reglement.
        $this->creerCommande(reference: 'CT-2026-0001', session: 'cs_test_1');

        $this->expectException(PDOException::class);

        $this->creerCommande(reference: 'CT-2026-0002', session: 'cs_test_1');
    }

    public function test_un_evenement_stripe_ne_s_insere_qu_une_fois(): void
    {
        // 01-modele §7.8 : l'idempotence est garantie par la CLE PRIMAIRE, donc
        // par la base et non par le code. Deux livraisons concurrentes du meme
        // evenement ne peuvent pas s'inserer toutes les deux.
        $this->insererEvenement('evt_1');

        $this->expectException(PDOException::class);

        $this->insererEvenement('evt_1');
    }

    public function test_une_oeuvre_ne_figure_qu_une_fois_dans_un_panier(): void
    {
        // 03-boutique §2 : contrainte d'unicite en base.
        $artwork = $this->creerOeuvre();
        $cart = $this->creerPanier();

        $this->pdo->exec(
            "INSERT INTO cart_items (cart_id, kind, artwork_id, qty, created_at)
             VALUES ({$cart}, 'original', {$artwork}, 1, NOW())"
        );

        $this->expectException(PDOException::class);

        $this->pdo->exec(
            "INSERT INTO cart_items (cart_id, kind, artwork_id, qty, created_at)
             VALUES ({$cart}, 'original', {$artwork}, 1, NOW())"
        );
    }

    public function test_une_ligne_de_panier_vise_une_cible_et_une_seule(): void
    {
        // Une ligne « original » qui porterait un variant_id serait facturee
        // deux fois ou pas du tout, selon le chemin de lecture.
        $artwork = $this->creerOeuvre();
        $variant = $this->creerVariante($this->creerProduit($artwork), stock: 5);
        $cart = $this->creerPanier();

        $this->expectException(PDOException::class);

        $this->pdo->exec(
            "INSERT INTO cart_items (cart_id, kind, artwork_id, variant_id, qty, created_at)
             VALUES ({$cart}, 'original', {$artwork}, {$variant}, 1, NOW())"
        );
    }

    // ------------------------------------------------ conservation des ventes

    public function test_supprimer_une_oeuvre_ne_detruit_pas_la_ligne_de_commande(): void
    {
        // 01-modele §7.9 : « toute ligne order_items conserve son libelle, son
        // prix, sa categorie et son taux de TVA meme si la source est supprimee
        // du catalogue ». C'est ON DELETE SET NULL, et surtout PAS CASCADE :
        // effacer la vente avec l'œuvre serait une perte comptable.
        $artwork = $this->creerOeuvre();
        $order = $this->creerCommande();

        $this->pdo->exec(
            "INSERT INTO order_items
                (order_id, kind, artwork_id, label, qty, unit_price_cents, total_cents,
                 vat_category, vat_rate_bps, ht_cents, vat_cents)
             VALUES ({$order}, 'original', {$artwork}, 'Articulation — 2026', 1, 45000, 45000,
                 'original_artwork', 0, 45000, 0)"
        );

        $this->pdo->exec("DELETE FROM artworks WHERE id = {$artwork}");

        $this->assertSame(1, $this->compter('order_items'));
        $this->assertSame(
            'Articulation — 2026',
            $this->valeur("SELECT label FROM order_items WHERE order_id = {$order}"),
        );
        $this->assertNull($this->valeur("SELECT artwork_id FROM order_items WHERE order_id = {$order}"));
        $this->assertSame(
            45000,
            (int) $this->valeur("SELECT unit_price_cents FROM order_items WHERE order_id = {$order}"),
        );
    }

    public function test_supprimer_une_oeuvre_vide_les_paniers_qui_la_contiennent(): void
    {
        // A l'inverse d'une commande, une ligne de panier n'a aucune valeur
        // historique : la garder afficherait une ligne fantome.
        $artwork = $this->creerOeuvre();
        $cart = $this->creerPanier();

        $this->pdo->exec(
            "INSERT INTO cart_items (cart_id, kind, artwork_id, qty, created_at)
             VALUES ({$cart}, 'original', {$artwork}, 1, NOW())"
        );

        $this->pdo->exec("DELETE FROM artworks WHERE id = {$artwork}");

        $this->assertSame(0, $this->compter('cart_items'));
    }

    public function test_supprimer_une_commande_emporte_ses_lignes(): void
    {
        $order = $this->creerCommande();
        $this->creerLigneDeCommande($order);

        $this->pdo->exec("DELETE FROM orders WHERE id = {$order}");

        $this->assertSame(0, $this->compter('order_items'));
    }

    // ------------------------------------------------------------ assistance

    private function creerOeuvre(): int
    {
        $this->pdo->exec(
            "INSERT INTO categories (position, is_published, created_at, updated_at)
             VALUES (0, 1, NOW(), NOW())"
        );
        $category = (int) $this->pdo->lastInsertId();

        $reference = 'REF-' . bin2hex(random_bytes(6));

        $this->pdo->exec(
            "INSERT INTO artworks (category_id, reference, status, price_cents, is_published, created_at, updated_at)
             VALUES ({$category}, '{$reference}', 'available', 45000, 1, NOW(), NOW())"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function creerProduit(int $artwork, string $kind = 'standard', ?int $editionSize = null): int
    {
        $size = $editionSize === null ? 'NULL' : (string) $editionSize;

        $this->pdo->exec(
            "INSERT INTO products (artwork_id, kind, edition_size, created_at, updated_at)
             VALUES ({$artwork}, '{$kind}', {$size}, NOW(), NOW())"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function creerVariante(int $product, int $stock): int
    {
        $sku = 'SKU-' . bin2hex(random_bytes(6));

        $this->pdo->exec(
            "INSERT INTO product_variants
                (product_id, sku, size_label, price_cents, stock_qty, weight_grams, created_at, updated_at)
             VALUES ({$product}, '{$sku}', '30 × 40 cm', 6000, {$stock}, 300, NOW(), NOW())"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function creerPanier(): int
    {
        $token = bin2hex(random_bytes(32));

        $this->pdo->exec(
            "INSERT INTO carts (token, locale, created_at, updated_at) VALUES ('{$token}', 'fr', NOW(), NOW())"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function creerCommande(string $reference = 'CT-2026-0001', ?string $session = null): int
    {
        $token = bin2hex(random_bytes(32));
        $stripe = $session === null ? 'NULL' : "'{$session}'";

        $this->pdo->exec(
            "INSERT INTO orders
                (reference, customer_email, customer_name, subtotal_cents, total_cents,
                 access_token, stripe_session_id, created_at, updated_at)
             VALUES ('{$reference}', 'acheteur@example.test', 'Acheteur', 45000, 45000,
                 '{$token}', {$stripe}, NOW(), NOW())"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function creerLigneDeCommande(
        int $order,
        int $total = 45000,
        int $ht = 45000,
        int $tva = 0,
        int $portTtc = 0,
        int $portHt = 0,
        int $portTva = 0,
    ): void {
        $this->pdo->exec(
            "INSERT INTO order_items
                (order_id, kind, label, qty, unit_price_cents, total_cents, vat_category,
                 vat_rate_bps, ht_cents, vat_cents,
                 shipping_share_cents, shipping_ht_cents, shipping_vat_cents)
             VALUES ({$order}, 'original', 'Articulation', 1, {$total}, {$total}, 'original_artwork',
                 0, {$ht}, {$tva}, {$portTtc}, {$portHt}, {$portTva})"
        );
    }

    private function insererEvenement(string $eventId): void
    {
        $this->pdo->exec(
            "INSERT INTO stripe_events (event_id, type, payload_hash, received_at)
             VALUES ('{$eventId}', 'checkout.session.completed', REPEAT('a', 64), NOW())"
        );
    }

    private function compter(string $table): int
    {
        // Nom de table en dur dans ce fichier, jamais une entree utilisateur.
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

    /**
     * @return list<string|null>
     */
    private function ligne(string $sql): array
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return [];
        }

        $row = $statement->fetch();

        if (!is_array($row)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): ?string => $v === null ? null : (string) $v,
            $row,
        ));
    }
}
