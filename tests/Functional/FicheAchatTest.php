<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Zone d'achat de la fiche œuvre (02-front-public §4.6, 03-boutique §1).
 *
 * Le bouton « Acquérir » n'apparait que si l'œuvre est disponible ET a un
 * prix. Les reproductions, elles, s'affichent en variantes avec leur propre
 * ajout au panier. Chaque ajout est un POST protege par jeton.
 */
final class FicheAchatTest extends FunctionalTestCase
{
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->create();
    }

    public function test_une_oeuvre_disponible_offre_un_bouton_d_ajout_au_panier(): void
    {
        $this->oeuvre();

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertSame(200, $reponse->status);
        // Un formulaire POST vers l'ajout au panier, portant l'identifiant.
        $this->assertMatchesRegularExpression(
            '#<form[^>]+action="[^"]*/panier/ajout"[^>]*>#',
            $reponse->body,
        );
        $this->assertStringContainsString('name="kind" value="original"', $reponse->body);
    }

    public function test_le_formulaire_d_achat_porte_un_jeton_csrf(): void
    {
        $this->oeuvre();

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertStringContainsString('name="' . Csrf::FIELD . '"', $reponse->body);
    }

    public function test_une_oeuvre_vendue_n_offre_aucun_bouton_d_achat(): void
    {
        $this->oeuvre(vendue: true);

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertStringNotContainsString('/panier/ajout', $reponse->body);
        $this->assertStringContainsStringIgnoringCase('vendue', $reponse->body);
    }

    public function test_une_oeuvre_sans_prix_n_offre_aucun_bouton_d_achat(): void
    {
        // 02-front-public §4.6 : un prix NULL signifie « non vendable ».
        $this->oeuvreNonVendable();

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertStringNotContainsString('/panier/ajout', $reponse->body);
    }

    public function test_les_reproductions_publiees_apparaissent_avec_leur_prix(): void
    {
        $artwork = $this->oeuvre();
        $this->reproduction($artwork, prix: 6000, size: '30 × 40 cm');
        $this->reproduction($artwork, prix: 9000, size: '50 × 70 cm');

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertStringContainsString('Tirage d’art', $reponse->body);
        $this->assertStringContainsString('30 × 40 cm', $reponse->body);
        $this->assertStringContainsString('60,00', $reponse->body);
        $this->assertStringContainsString('90,00', $reponse->body);
        $this->assertStringContainsString('name="kind" value="reproduction"', $reponse->body);
    }

    public function test_une_variante_en_rupture_n_apparait_pas(): void
    {
        $artwork = $this->oeuvre();
        $this->reproduction($artwork, size: '30 × 40 cm', stock: 3);
        $this->reproduction($artwork, size: '50 × 70 cm', stock: 0);

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertStringContainsString('30 × 40 cm', $reponse->body);
        $this->assertStringNotContainsString('50 × 70 cm', $reponse->body);
    }

    public function test_une_reproduction_non_publiee_n_apparait_pas(): void
    {
        $artwork = $this->oeuvre();
        $this->reproduction($artwork, publie: false);

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertStringNotContainsString('Tirage d’art', $reponse->body);
    }

    public function test_une_oeuvre_non_vendable_mais_avec_reproductions_reste_achetable(): void
    {
        // Un original « non à vendre » peut exister uniquement en tirages : la
        // zone reproductions doit vivre meme quand l'original ne se vend pas.
        $artwork = $this->oeuvreNonVendable();
        $this->reproduction($artwork);

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertStringContainsString('name="kind" value="reproduction"', $reponse->body);
    }

    // ------------------------------------------------------------ assistance

    private function oeuvre(int $prix = 45000, bool $vendue = false): int
    {
        $factory = (new ArtworkFactory($this->pdo))->published()
            ->translated('fr', 'articulation', 'Articulation');

        $factory = $vendue ? $factory->sold()->priced($prix) : $factory->available()->priced($prix);

        return $factory->create($this->categoryId);
    }

    private function oeuvreNonVendable(): int
    {
        return (new ArtworkFactory($this->pdo))->published()->notForSale()
            ->translated('fr', 'articulation', 'Articulation')
            ->create($this->categoryId);
    }

    private function reproduction(
        int $artwork,
        int $prix = 6000,
        string $size = '30 × 40 cm',
        int $stock = 5,
        bool $publie = true,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, is_published, created_at, updated_at)
             VALUES (:art, :kind, :pub, NOW(), NOW())'
        )->execute(['art' => $artwork, 'kind' => 'standard', 'pub' => $publie ? 1 : 0]);
        $product = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :l, :t)'
        )->execute(['id' => $product, 'l' => 'fr', 't' => 'Tirage d’art']);

        $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           created_at, updated_at)
             VALUES (:prod, :sku, :size, :price, :stock, 300, NOW(), NOW())'
        )->execute([
            'prod' => $product,
            'sku' => 'ART-' . bin2hex(random_bytes(4)),
            'size' => $size,
            'price' => $prix,
            'stock' => $stock,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
