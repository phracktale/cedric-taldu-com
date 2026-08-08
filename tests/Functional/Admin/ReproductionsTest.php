<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Domain\Shop\ManagedReproductions;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Back-office des reproductions (04-back-office, 08-lots lot 3).
 *
 * L'artiste cree une offre de tirage rattachee a une œuvre, lui ajoute des
 * variantes (taille, encadrement, prix, stock), et publie. Par defaut, une
 * reproduction est en TVA a 20 % : un gicle reste photomecanique (decision du
 * 2026-07-21).
 */
final class ReproductionsTest extends AdminTestCase
{
    private int $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $category = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->create();
        $this->artwork = (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')
            ->create($category);
    }

    public function test_la_page_liste_les_reproductions_d_une_oeuvre(): void
    {
        $reponse = $this->requete('GET', $this->base());

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Articulation', $reponse->body);
    }

    public function test_l_ajout_de_tirages_geres_cree_le_produit_et_les_variantes(): void
    {
        // L'artiste ne saisit qu'un prix par taille : le SKU Prodigi, le cadrage,
        // le libellé et le poids viennent du catalogue. Le produit standard est
        // créé à la volée, sans titre à saisir.
        $reponse = $this->postAvecJeton($this->base(), [
            ManagedReproductions::field('GLOBAL-HGE-12X16') => '60',
            ManagedReproductions::field('GLOBAL-HGE-16X20') => '90',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(1, (int) $this->valeur("SELECT COUNT(*) FROM products WHERE artwork_id = {$this->artwork}"));
        $this->assertSame(2, (int) $this->valeur('SELECT COUNT(*) FROM product_variants'));
        $this->assertSame(
            'GLOBAL-HGE-16X20',
            $this->valeur("SELECT prodigi_sku FROM product_variants WHERE price_cents = 9000"),
        );
        $this->assertSame(
            'fillPrintArea',
            $this->valeur("SELECT prodigi_sizing FROM product_variants WHERE price_cents = 9000"),
        );
        $this->assertSame('40 × 50 cm', $this->valeur("SELECT size_label FROM product_variants WHERE price_cents = 9000"));
    }

    public function test_l_ajout_sans_aucun_prix_ne_cree_rien(): void
    {
        $this->postAvecJeton($this->base(), []);

        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM products'));
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM product_variants'));
    }

    public function test_une_taille_deja_proposee_n_est_pas_dupliquee(): void
    {
        $this->postAvecJeton($this->base(), [ManagedReproductions::field('GLOBAL-HGE-12X16') => '60']);
        $this->postAvecJeton($this->base(), [ManagedReproductions::field('GLOBAL-HGE-12X16') => '75']);

        // Une seule reproduction, une seule taille : le second ajout est ignoré.
        $this->assertSame(1, (int) $this->valeur("SELECT COUNT(*) FROM products WHERE artwork_id = {$this->artwork}"));
        $this->assertSame(1, (int) $this->valeur('SELECT COUNT(*) FROM product_variants'));
    }

    public function test_un_tirage_ajoute_nait_en_tva_a_vingt_pourcent(): void
    {
        // Decision du 2026-07-21 : standard_goods par defaut.
        $this->postAvecJeton($this->base(), [ManagedReproductions::field('GLOBAL-HGE-12X16') => '60']);

        $this->assertSame('standard_goods', $this->valeur('SELECT vat_category FROM products'));
    }

    public function test_un_tirage_ajoute_nait_non_publie(): void
    {
        $this->postAvecJeton($this->base(), [ManagedReproductions::field('GLOBAL-HGE-12X16') => '60']);

        $this->assertSame('0', $this->valeur('SELECT is_published FROM products'));
    }

    // ----------------------------------------------------- édition limitée

    public function test_une_edition_limitee_se_cree_en_circuit_manuel(): void
    {
        // Format + prix + taille d'édition, sans titre ni SKU technique. Le
        // circuit est manuel (rehaussée à l'atelier), jamais Prodigi auto.
        $reponse = $this->postAvecJeton($this->base() . '/edition-limitee', [
            'format' => '40 × 50 cm',
            'prix' => '250',
            'taille_edition' => '30',
            'poids' => '600',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame('limited', $this->valeur('SELECT kind FROM products'));
        $this->assertSame('artist_manual', $this->valeur('SELECT processing_mode FROM products'));
        $this->assertSame(30, (int) $this->valeur('SELECT edition_size FROM products'));
        // Une variante numérotable, prix en centimes, stock calé sur l'édition.
        $this->assertSame(25000, (int) $this->valeur('SELECT price_cents FROM product_variants'));
        $this->assertSame(30, (int) $this->valeur('SELECT stock_qty FROM product_variants'));
        $this->assertSame('40 × 50 cm', $this->valeur('SELECT size_label FROM product_variants'));
    }

    public function test_une_edition_limitee_sans_taille_est_refusee(): void
    {
        $this->postAvecJeton($this->base() . '/edition-limitee', [
            'format' => '40 × 50 cm',
            'prix' => '250',
            'taille_edition' => '',
        ]);

        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM products'));
    }

    public function test_la_page_affiche_la_nature_et_le_circuit(): void
    {
        $this->postAvecJeton($this->base() . '/edition-limitee', [
            'format' => '40 × 50 cm',
            'prix' => '250',
            'taille_edition' => '30',
        ]);

        $reponse = $this->requete('GET', $this->base());

        $this->assertStringContainsString('Édition limitée', $reponse->body);
        $this->assertStringContainsString('atelier', $reponse->body);
    }

    public function test_une_variante_s_ajoute_a_une_reproduction(): void
    {
        $product = $this->creerProduit();

        $reponse = $this->postAvecJeton($this->reproduction($product) . '/variantes', [
            'sku' => 'ART-3040',
            'taille' => '30 × 40 cm',
            'prix' => '60',
            'stock' => '5',
            'poids' => '300',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(1, (int) $this->valeur("SELECT COUNT(*) FROM product_variants WHERE product_id = {$product}"));
        // Prix saisi en euros, stocke en centimes.
        $this->assertSame(6000, (int) $this->valeur('SELECT price_cents FROM product_variants'));
        $this->assertSame(5, (int) $this->valeur('SELECT stock_qty FROM product_variants'));
    }

    public function test_un_sku_deja_pris_est_refuse_sans_erreur_sql(): void
    {
        $product = $this->creerProduit();
        $this->creerVariante($product, 'ART-3040', '30 × 40 cm');

        $reponse = $this->postAvecJeton($this->reproduction($product) . '/variantes', [
            'sku' => 'ART-3040',
            'taille' => '50 × 70 cm',
            'prix' => '90',
            'stock' => '5',
            'poids' => '400',
        ]);

        // Refusee proprement, pas de 500 ni de doublon.
        $this->assertNotSame(500, $reponse->status);
        $this->assertSame(1, (int) $this->valeur('SELECT COUNT(*) FROM product_variants'));
    }

    public function test_un_stock_se_met_a_jour(): void
    {
        $product = $this->creerProduit();
        $variant = $this->creerVariante($product, 'ART-3040', '30 × 40 cm');

        $this->postAvecJeton('/cedric-taldu/admin/variantes/' . $variant, [
            'sku' => 'ART-3040',
            'taille' => '30 × 40 cm',
            'prix' => '60',
            'stock' => '12',
            'poids' => '300',
        ]);

        $this->assertSame(12, (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$variant}"));
    }

    public function test_une_variante_se_supprime(): void
    {
        $product = $this->creerProduit();
        $variant = $this->creerVariante($product, 'ART-3040', '30 × 40 cm');

        $this->postAvecJeton('/cedric-taldu/admin/variantes/' . $variant . '/suppression');

        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM product_variants'));
    }

    public function test_une_reproduction_se_publie(): void
    {
        $product = $this->creerProduit();

        $this->postAvecJeton($this->reproduction($product) . '/publication');

        $this->assertSame('1', $this->valeur("SELECT is_published FROM products WHERE id = {$product}"));
    }

    public function test_une_reproduction_se_supprime_avec_ses_variantes(): void
    {
        $product = $this->creerProduit();
        $this->creerVariante($product, 'ART-3040', '30 × 40 cm');

        $this->postAvecJeton($this->reproduction($product) . '/suppression');

        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM products'));
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM product_variants'));
    }

    public function test_l_ajout_sans_jeton_csrf_est_refuse(): void
    {
        $reponse = $this->requete('POST', $this->base(), post: [
            ManagedReproductions::field('GLOBAL-HGE-12X16') => '60',
        ]);

        $this->assertContains($reponse->status, [403, 419]);
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM products'));
    }

    // ------------------------------------------------------------ assistance

    private function base(): string
    {
        return '/cedric-taldu/admin/oeuvres/' . $this->artwork . '/reproductions';
    }

    // ------------------------------------------------------ mapping Prodigi

    public function test_une_variante_porte_son_mapping_prodigi(): void
    {
        // Le SKU Prodigi et le sizing disent quoi imprimer et comment le cadrer.
        $product = $this->creerProduit();

        $this->postAvecJeton($this->reproduction($product) . '/variantes', [
            'sku' => 'ART-3040', 'taille' => '30 × 40 cm', 'prix' => '60', 'stock' => '5', 'poids' => '300',
            'prodigi_sku' => 'GLOBAL-FAP-16X24', 'prodigi_sizing' => 'fitPrintArea',
        ]);

        $this->assertSame('GLOBAL-FAP-16X24', $this->valeur('SELECT prodigi_sku FROM product_variants'));
        $this->assertSame('fitPrintArea', $this->valeur('SELECT prodigi_sizing FROM product_variants'));
    }

    public function test_un_sizing_prodigi_inconnu_retombe_sur_le_defaut(): void
    {
        // Liste close : le sizing finit dans un appel API, une valeur inventée y
        // serait rejetée par Prodigi. On la ramène au défaut.
        $product = $this->creerProduit();

        $this->postAvecJeton($this->reproduction($product) . '/variantes', [
            'sku' => 'ART-A', 'taille' => 'A', 'prix' => '60', 'stock' => '5', 'poids' => '300',
            'prodigi_sku' => 'X', 'prodigi_sizing' => 'bidon',
        ]);

        $this->assertSame('fillPrintArea', $this->valeur('SELECT prodigi_sizing FROM product_variants'));
    }

    public function test_le_mapping_prodigi_d_une_variante_existante_se_modifie(): void
    {
        $product = $this->creerProduit();
        $variant = $this->creerVariante($product, 'ART-3040', '30 × 40 cm');

        $this->postAvecJeton('/cedric-taldu/admin/variantes/' . $variant, [
            'sku' => 'ART-3040', 'taille' => '30 × 40 cm', 'prix' => '60', 'stock' => '5', 'poids' => '300',
            'prodigi_sku' => 'GLOBAL-CFPM-16X20', 'prodigi_sizing' => 'fillPrintArea',
        ]);

        $this->assertSame(
            'GLOBAL-CFPM-16X20',
            $this->valeur("SELECT prodigi_sku FROM product_variants WHERE id = {$variant}"),
        );
    }

    private function reproduction(int $product): string
    {
        return '/cedric-taldu/admin/reproductions/' . $product;
    }

    private function creerProduit(string $kind = 'standard'): int
    {
        $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, edition_size, vat_category, is_published,
                                   created_at, updated_at)
             VALUES (:art, :kind, :size, :vat, 0, NOW(), NOW())'
        )->execute([
            'art' => $this->artwork,
            'kind' => $kind,
            'size' => $kind === 'limited' ? 30 : null,
            'vat' => 'standard_goods',
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :l, :t)'
        )->execute(['id' => $id, 'l' => 'fr', 't' => 'Tirage d’art']);

        return $id;
    }

    private function creerVariante(int $product, string $sku, string $size): int
    {
        $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           created_at, updated_at)
             VALUES (:prod, :sku, :size, 6000, 5, 300, NOW(), NOW())'
        )->execute(['prod' => $product, 'sku' => $sku, 'size' => $size]);

        return (int) $this->pdo->lastInsertId();
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
