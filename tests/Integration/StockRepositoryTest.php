<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Domain\Catalog\ArtworkStatus;
use App\Repository\StockRepository;
use DateTimeImmutable;
use PDO;
use Tests\Support\DatabaseTestCase;

/**
 * Les quatre ecritures qui engagent du stock et de l'argent.
 *
 * Chacune est un UPDATE CONDITIONNEL dont on VERIFIE LE NOMBRE DE LIGNES
 * AFFECTEES. C'est le point que le lot 2 a rate ailleurs : une valeur ecrite
 * sans etre relue ne prouve rien. Ici, la condition est DANS le WHERE, et c'est
 * la base — pas le code — qui arbitre entre deux acheteurs simultanes.
 *
 * Les tests de concurrence emploient DEUX connexions PDO distinctes
 * (07-tests-tdd §2.2). Ils ne peuvent donc pas se derouler dans la transaction
 * annulee de DatabaseTestCase : ils gerent et nettoient leurs donnees eux-memes.
 */
final class StockRepositoryTest extends DatabaseTestCase
{
    private StockRepository $repository;

    /**
     * Rubriques creees HORS transaction par les tests de concurrence.
     *
     * Sans ce suivi, elles survivent au test et faussent les suites suivantes :
     * tests/CLAUDE.md exige qu'aucun test ne depende de l'etat laisse par un
     * autre, et une rubrique orpheline suffit a faire echouer un comptage
     * fonctionnel a l'autre bout de la suite.
     *
     * @var list<int>
     */
    private array $rubriquesHorsTransaction = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rubriquesHorsTransaction = [];
        $this->repository = new StockRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->nettoyerHorsTransaction();

        parent::tearDown();
    }

    // ------------------------------------------------- reservation d'une œuvre

    public function test_une_oeuvre_disponible_se_reserve(): void
    {
        $artwork = $this->creerOeuvre();
        $echeance = new DateTimeImmutable('2026-07-21 15:00:00');

        $this->assertTrue($this->repository->reserve($artwork, $echeance));

        $this->assertSame('reserved', $this->statut($artwork));
        $this->assertSame(
            '2026-07-21 15:00:00',
            $this->valeur("SELECT reserved_until FROM artworks WHERE id = {$artwork}"),
        );
    }

    public function test_une_oeuvre_reservee_par_un_paiement_en_cours_ne_se_reserve_pas(): void
    {
        // 03-boutique §3 : « si le statut a change entre-temps, la transaction
        // est annulee et l'utilisateur revient au panier ».
        $artwork = $this->creerOeuvre(statut: 'reserved', reserveJusquA: '2026-07-21 15:00:00');

        $this->assertFalse($this->repository->reserve(
            $artwork,
            new DateTimeImmutable('2026-07-21 15:30:00'),
            now: new DateTimeImmutable('2026-07-21 14:30:00'),
        ));
    }

    public function test_une_reservation_sans_echeance_ne_bloque_pas_l_oeuvre(): void
    {
        // Une ligne reserved sans reserved_until est une incoherence de
        // donnees : ArtworkStatus::effectiveAt() la traite deja comme expiree,
        // et le depot doit s'aligner. La tenir pour eternelle retirerait la
        // piece de la vente pour toujours, sans trace ni recours.
        $artwork = $this->creerOeuvre(statut: 'reserved');

        $this->assertTrue($this->repository->reserve(
            $artwork,
            new DateTimeImmutable('2026-07-21 15:00:00'),
            now: new DateTimeImmutable('2026-07-21 14:30:00'),
        ));
    }

    public function test_une_oeuvre_vendue_ne_se_reserve_pas(): void
    {
        $artwork = $this->creerOeuvre(statut: 'sold');

        $this->assertFalse($this->repository->reserve($artwork, new DateTimeImmutable('2026-07-21 15:00:00')));
        $this->assertSame('sold', $this->statut($artwork));
    }

    public function test_une_reservation_echue_est_reprise_par_un_autre_acheteur(): void
    {
        // 01-modele §7.3 : la reservation expiree libere l'œuvre. Sans cela, un
        // paiement abandonne bloquerait la piece jusqu'au prochain cron — et le
        // cron n'est pas garanti sur un mutualise.
        $artwork = $this->creerOeuvre(statut: 'reserved', reserveJusquA: '2026-07-21 14:00:00');

        $this->assertTrue($this->repository->reserve(
            $artwork,
            new DateTimeImmutable('2026-07-21 15:00:00'),
            now: new DateTimeImmutable('2026-07-21 14:00:01'),
        ));
    }

    public function test_une_reservation_en_cours_n_est_pas_reprise(): void
    {
        $artwork = $this->creerOeuvre(statut: 'reserved', reserveJusquA: '2026-07-21 14:00:00');

        $this->assertFalse($this->repository->reserve(
            $artwork,
            new DateTimeImmutable('2026-07-21 15:00:00'),
            now: new DateTimeImmutable('2026-07-21 13:59:59'),
        ));
    }

    public function test_liberer_une_reservation_remet_l_oeuvre_en_vente(): void
    {
        // checkout.session.expired et payment_intent.payment_failed
        // (03-boutique §6).
        $artwork = $this->creerOeuvre(statut: 'reserved', reserveJusquA: '2026-07-21 14:00:00');

        $this->assertTrue($this->repository->release($artwork));

        $this->assertSame('available', $this->statut($artwork));
        $this->assertNull($this->valeur("SELECT reserved_until FROM artworks WHERE id = {$artwork}"));
    }

    public function test_liberer_une_oeuvre_vendue_ne_fait_rien(): void
    {
        // Le cas dangereux : un checkout.session.expired qui arrive APRES le
        // checkout.session.completed remettrait en vente une piece deja payee.
        $artwork = $this->creerOeuvre(statut: 'sold');

        $this->assertFalse($this->repository->release($artwork));
        $this->assertSame('sold', $this->statut($artwork));
    }

    // ------------------------------------------------------ vente d'une œuvre

    public function test_une_oeuvre_reservee_se_vend(): void
    {
        $artwork = $this->creerOeuvre(statut: 'reserved', reserveJusquA: '2026-07-21 15:00:00');

        $this->assertTrue($this->repository->markSold($artwork));

        $this->assertSame('sold', $this->statut($artwork));
        $this->assertNull($this->valeur("SELECT reserved_until FROM artworks WHERE id = {$artwork}"));
    }

    public function test_une_oeuvre_disponible_se_vend_aussi(): void
    {
        // 01-modele §7.2 autorise « available|reserved → sold » : la reservation
        // a pu expirer entre la creation de la commande et l'arrivee du webhook,
        // et le client a bel et bien paye.
        $artwork = $this->creerOeuvre();

        $this->assertTrue($this->repository->markSold($artwork));
        $this->assertSame('sold', $this->statut($artwork));
    }

    public function test_une_oeuvre_deja_vendue_ne_se_revend_pas(): void
    {
        // L'invariant central du lot : 01-modele §7.2. C'est ce faux qui declenche
        // le signalement d'anomalie et le remboursement manuel (03-boutique §8.5).
        $artwork = $this->creerOeuvre(statut: 'sold');

        $this->assertFalse($this->repository->markSold($artwork));
    }

    public function test_un_brouillon_ne_se_vend_pas(): void
    {
        $artwork = $this->creerOeuvre(statut: 'draft');

        $this->assertFalse($this->repository->markSold($artwork));
        $this->assertSame('draft', $this->statut($artwork));
    }

    // ------------------------------------------------------ decrement de stock

    public function test_un_stock_suffisant_se_decremente(): void
    {
        $variant = $this->creerVariante(stock: 5);

        $this->assertTrue($this->repository->decrementStock($variant, 3));

        $this->assertSame(2, $this->stock($variant));
    }

    public function test_un_stock_exactement_egal_se_decremente(): void
    {
        $variant = $this->creerVariante(stock: 3);

        $this->assertTrue($this->repository->decrementStock($variant, 3));

        $this->assertSame(0, $this->stock($variant));
    }

    public function test_un_stock_insuffisant_ne_se_decremente_pas(): void
    {
        // 01-modele §7.5 : `WHERE stock_qty >= :q` avec verification du nombre
        // de lignes affectees. Le stock reste INTACT — pas de decrement partiel.
        $variant = $this->creerVariante(stock: 2);

        $this->assertFalse($this->repository->decrementStock($variant, 3));

        $this->assertSame(2, $this->stock($variant));
    }

    public function test_un_stock_nul_ne_se_decremente_pas(): void
    {
        $variant = $this->creerVariante(stock: 0);

        $this->assertFalse($this->repository->decrementStock($variant, 1));
        $this->assertSame(0, $this->stock($variant));
    }

    // -------------------------------------------- attribution des numeros

    public function test_les_numeros_d_edition_sont_attribues_a_la_suite(): void
    {
        // 03-boutique §6 : « les numeros attribues sont editions_sold_avant + 1
        // … + q ».
        $product = $this->creerProduit(editionSize: 30, dejaVendus: 4);

        $this->assertSame([5, 6, 7], $this->repository->claimEditionNumbers($product, 3));
        $this->assertSame(7, $this->vendus($product));
    }

    public function test_une_edition_se_remplit_jusqu_a_son_dernier_numero(): void
    {
        $product = $this->creerProduit(editionSize: 30, dejaVendus: 28);

        $this->assertSame([29, 30], $this->repository->claimEditionNumbers($product, 2));
        $this->assertSame(30, $this->vendus($product));
    }

    public function test_une_edition_epuisee_n_attribue_aucun_numero(): void
    {
        // 03-boutique §6 : zero ligne affectee -> edition epuisee. La commande
        // est marquee payee malgre tout, la ligne signalee en anomalie. « On ne
        // perd jamais un paiement encaisse. »
        $product = $this->creerProduit(editionSize: 30, dejaVendus: 30);

        $this->assertNull($this->repository->claimEditionNumbers($product, 1));
        $this->assertSame(30, $this->vendus($product));
    }

    public function test_une_demande_qui_deborde_l_edition_n_attribue_rien(): void
    {
        // Tout ou rien : attribuer deux numeros sur trois laisserait une
        // commande a moitie honoree, sans que rien ne le dise.
        $product = $this->creerProduit(editionSize: 30, dejaVendus: 28);

        $this->assertNull($this->repository->claimEditionNumbers($product, 3));
        $this->assertSame(28, $this->vendus($product));
    }

    public function test_une_edition_non_limitee_n_attribue_pas_de_numero(): void
    {
        // products.kind = 'standard' : il n'y a pas de numerotation a rendre,
        // et ce n'est pas une anomalie.
        $product = $this->creerProduit(editionSize: null, dejaVendus: 0);

        $this->assertSame([], $this->repository->claimEditionNumbers($product, 2));
    }

    // ------------------------------------------------------------ concurrence

    public function test_deux_acheteurs_simultanes_ne_vendent_pas_deux_fois_la_meme_oeuvre(): void
    {
        // 03-boutique §8.5, le scenario que tout ce lot existe pour empecher.
        // Deux CONNEXIONS distinctes, chacune dans sa transaction : c'est la
        // base qui arbitre, pas le code.
        $artwork = $this->creerOeuvreHorsTransaction();

        $seconde = self::secondeConnexion();
        $premierDepot = new StockRepository($this->pdo);
        $secondDepot = new StockRepository($seconde);

        $this->pdo->beginTransaction();
        $seconde->beginTransaction();

        $premier = $premierDepot->markSold($artwork);
        $this->pdo->commit();

        $second = $secondDepot->markSold($artwork);
        $seconde->commit();

        $this->assertTrue($premier, 'Le premier acheteur doit emporter l’œuvre.');
        $this->assertFalse($second, 'Le second doit echouer, et non ecraser la vente.');

    }

    public function test_deux_decrements_simultanes_ne_rendent_jamais_le_stock_negatif(): void
    {
        // 07-tests-tdd §2.2 : « deux decrements simultanes sur le meme stock —
        // jamais de stock negatif ».
        $variant = $this->creerVarianteHorsTransaction(stock: 3);

        $seconde = self::secondeConnexion();

        $this->pdo->beginTransaction();
        $premier = (new StockRepository($this->pdo))->decrementStock($variant, 2);
        $this->pdo->commit();

        $seconde->beginTransaction();
        $second = (new StockRepository($seconde))->decrementStock($variant, 2);
        $seconde->commit();

        $this->assertTrue($premier);
        $this->assertFalse($second, 'Le second decrement doit echouer, pas passer le stock a -1.');
        $this->assertSame(1, $this->stock($variant));

    }

    public function test_deux_attributions_simultanees_n_epuisent_pas_l_edition_deux_fois(): void
    {
        // Deux acheteurs du dernier exemplaire d'une edition de 30.
        $product = $this->creerProduitHorsTransaction(editionSize: 30, dejaVendus: 29);

        $seconde = self::secondeConnexion();

        $this->pdo->beginTransaction();
        $premier = (new StockRepository($this->pdo))->claimEditionNumbers($product, 1);
        $this->pdo->commit();

        $seconde->beginTransaction();
        $second = (new StockRepository($seconde))->claimEditionNumbers($product, 1);
        $seconde->commit();

        $this->assertSame([30], $premier);
        $this->assertNull($second, 'Le second doit repartir sans numero, pas recevoir le 31e.');
        $this->assertSame(30, $this->vendus($product));

    }

    // ------------------------------------------------------------- assistance

    private static function secondeConnexion(): PDO
    {
        // Connexion propre, distincte de celle partagee par DatabaseTestCase :
        // deux transactions concurrentes ne peuvent pas vivre sur une seule.
        $pdo = (new Database(
            host: getenv('DB_TEST_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('DB_TEST_PORT') ?: (getenv('DB_PORT') ?: '13306')),
            name: getenv('DB_TEST_NAME') ?: 'cedrictaldu_test',
            user: getenv('DB_MIGRATION_USER') ?: 'cedrictaldu_migrate',
            password: getenv('DB_MIGRATION_PASSWORD') ?: 'migration',
        ))->connect();

        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

        return $pdo;
    }

    private function creerOeuvre(string $statut = 'available', ?string $reserveJusquA = null): int
    {
        $this->pdo->exec(
            'INSERT INTO categories (position, is_published, created_at, updated_at) VALUES (0, 1, NOW(), NOW())'
        );
        $category = (int) $this->pdo->lastInsertId();

        // Hors transaction, cette rubrique est deja commitee : il faudra la
        // supprimer a la main en tearDown.
        if (!$this->pdo->inTransaction()) {
            $this->rubriquesHorsTransaction[] = $category;
        }

        $reference = 'REF-' . bin2hex(random_bytes(6));
        $echeance = $reserveJusquA === null ? 'NULL' : "'{$reserveJusquA}'";

        $this->pdo->exec(
            "INSERT INTO artworks
                (category_id, reference, status, reserved_until, price_cents, is_published, created_at, updated_at)
             VALUES ({$category}, '{$reference}', '{$statut}', {$echeance}, 45000, 1, NOW(), NOW())"
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Les tests de concurrence pilotent leurs propres transactions : leurs
     * donnees doivent donc exister EN DEHORS de celle de DatabaseTestCase, sans
     * quoi la seconde connexion ne les verrait pas.
     */
    private function creerOeuvreHorsTransaction(): int
    {
        $this->pdo->rollBack();

        $artwork = $this->creerOeuvre();

        return $artwork;
    }

    private function creerVariante(int $stock): int
    {
        $product = $this->creerProduit(editionSize: null, dejaVendus: 0);
        $sku = 'SKU-' . bin2hex(random_bytes(6));

        $this->pdo->exec(
            "INSERT INTO product_variants
                (product_id, sku, size_label, price_cents, stock_qty, weight_grams, created_at, updated_at)
             VALUES ({$product}, '{$sku}', '30 × 40 cm', 6000, {$stock}, 300, NOW(), NOW())"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function creerVarianteHorsTransaction(int $stock): int
    {
        $this->pdo->rollBack();

        return $this->creerVariante($stock);
    }

    private function creerProduit(?int $editionSize, int $dejaVendus): int
    {
        $artwork = $this->creerOeuvre();
        $kind = $editionSize === null ? 'standard' : 'limited';
        $size = $editionSize === null ? 'NULL' : (string) $editionSize;

        $this->pdo->exec(
            "INSERT INTO products (artwork_id, kind, edition_size, editions_sold, created_at, updated_at)
             VALUES ({$artwork}, '{$kind}', {$size}, {$dejaVendus}, NOW(), NOW())"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function creerProduitHorsTransaction(int $editionSize, int $dejaVendus): int
    {
        $this->pdo->rollBack();

        return $this->creerProduit($editionSize, $dejaVendus);
    }

    /**
     * Efface tout ce que les tests de concurrence ont commite.
     *
     * L'ordre importe : artworks porte ON DELETE RESTRICT vers categories, donc
     * les œuvres partent d'abord. Elles emportent en cascade leurs produits et
     * leurs variantes.
     */
    private function nettoyerHorsTransaction(): void
    {
        if ($this->rubriquesHorsTransaction === []) {
            return;
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        foreach ($this->rubriquesHorsTransaction as $category) {
            $this->pdo->exec("DELETE FROM artworks WHERE category_id = {$category}");
            $this->pdo->exec("DELETE FROM categories WHERE id = {$category}");
        }

        $this->rubriquesHorsTransaction = [];
    }

    private function statut(int $artwork): ?string
    {
        return $this->valeur("SELECT status FROM artworks WHERE id = {$artwork}");
    }

    private function stock(int $variant): int
    {
        return (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$variant}");
    }

    private function vendus(int $product): int
    {
        return (int) $this->valeur("SELECT editions_sold FROM products WHERE id = {$product}");
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

    /** @return list<ArtworkStatus> */
    public static function statutsVendables(): array
    {
        return [ArtworkStatus::Available, ArtworkStatus::Reserved];
    }
}
