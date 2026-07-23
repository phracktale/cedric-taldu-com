<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use App\Core\Response;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Panier public (03-boutique §2).
 *
 * Le panier vit en base, reference par un jeton en cookie. AUCUN PRIX ne
 * transite par le client : chaque affichage recalcule depuis le catalogue.
 * Ces tests suivent le cookie d'une requete a l'autre, comme un navigateur.
 */
final class PanierTest extends FunctionalTestCase
{
    private const COOKIE = 'ct_cart';

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->create();
    }

    public function test_un_panier_neuf_est_vide(): void
    {
        $reponse = $this->requete('GET', '/cedric-taldu/fr/panier');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('panier', strtolower($reponse->body));
    }

    public function test_une_oeuvre_disponible_s_ajoute_au_panier(): void
    {
        $artwork = $this->oeuvre();

        $reponse = $this->ajouter(['kind' => 'original', 'id' => (string) $artwork]);

        // POST-Redirect-GET : sans JS, l'ajout redirige vers le panier.
        $this->assertSame(303, $reponse->status);
        $this->assertStringContainsString('/panier', $reponse->header('Location') ?? '');
    }

    public function test_le_panier_affiche_l_oeuvre_ajoutee_avec_son_prix(): void
    {
        $artwork = $this->oeuvre(prix: 45000);

        $cookie = $this->cookieApres($this->ajouter(['kind' => 'original', 'id' => (string) $artwork]));
        $reponse = $this->requete('GET', '/cedric-taldu/fr/panier', cookies: [self::COOKIE => $cookie]);

        $this->assertStringContainsString('Articulation', $reponse->body);
        $this->assertStringContainsString('450,00', $reponse->body);
    }

    public function test_le_prix_affiche_vient_du_catalogue_et_non_du_client(): void
    {
        // 03-boutique §8.1 : un montant renvoye par le client est ignore.
        $artwork = $this->oeuvre(prix: 45000);

        $cookie = $this->cookieApres($this->ajouter([
            'kind' => 'original',
            'id' => (string) $artwork,
            'prix' => '1',
            'total' => '1',
        ]));
        $reponse = $this->requete('GET', '/cedric-taldu/fr/panier', cookies: [self::COOKIE => $cookie]);

        $this->assertStringContainsString('450,00', $reponse->body);
        $this->assertStringNotContainsString('0,01', $reponse->body);
    }

    public function test_une_oeuvre_vendue_ne_s_ajoute_pas(): void
    {
        $artwork = $this->oeuvre(vendue: true);

        $reponse = $this->ajouter(['kind' => 'original', 'id' => (string) $artwork]);

        // La ligne est refusee ou retiree ; le panier reste vide.
        $cookie = $this->cookieApres($reponse);
        $panier = $this->requete('GET', '/cedric-taldu/fr/panier', cookies: [self::COOKIE => $cookie]);

        $this->assertStringNotContainsString('Articulation', $panier->body);
    }

    public function test_une_oeuvre_acquise_entre_temps_est_retiree_avec_un_message(): void
    {
        // 03-boutique §2 : « œuvre passee en sold -> ligne retiree, message ».
        $artwork = $this->oeuvre();
        $cookie = $this->cookieApres($this->ajouter(['kind' => 'original', 'id' => (string) $artwork]));

        $this->pdo->exec("UPDATE artworks SET status = 'sold' WHERE id = {$artwork}");

        $reponse = $this->requete('GET', '/cedric-taldu/fr/panier', cookies: [self::COOKIE => $cookie]);

        $this->assertStringNotContainsString('450,00', $reponse->body);
        $this->assertStringContainsStringIgnoringCase('acquise', $reponse->body);
    }

    public function test_une_quantite_se_met_a_jour(): void
    {
        $variant = $this->reproduction(stock: 10);
        $cookie = $this->cookieApres($this->ajouter(['kind' => 'reproduction', 'id' => (string) $variant]));

        $reponse = $this->postPanier('/cedric-taldu/fr/panier/quantite', [
            'kind' => 'reproduction',
            'id' => (string) $variant,
            'quantite' => '3',
        ], $cookie);

        $this->assertSame(303, $reponse->status);

        $panier = $this->requete('GET', '/cedric-taldu/fr/panier', cookies: [self::COOKIE => $cookie]);
        $this->assertSame(3, (int) $this->valeur('SELECT qty FROM cart_items'));
        $this->assertStringNotContainsString('Articulation', $panier->body ?: 'ok');
    }

    public function test_une_ligne_se_retire(): void
    {
        $variant = $this->reproduction();
        $cookie = $this->cookieApres($this->ajouter(['kind' => 'reproduction', 'id' => (string) $variant]));

        $this->postPanier('/cedric-taldu/fr/panier/retrait', [
            'kind' => 'reproduction',
            'id' => (string) $variant,
        ], $cookie);

        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM cart_items'));
    }

    public function test_un_ajout_sans_jeton_csrf_est_refuse(): void
    {
        // 06-securite §3 : l'ajout au panier est un POST, donc protege.
        $artwork = $this->oeuvre();

        $reponse = $this->requete('POST', '/cedric-taldu/fr/panier/ajout', post: [
            'kind' => 'original',
            'id' => (string) $artwork,
        ]);

        $this->assertContains($reponse->status, [403, 419]);
    }

    public function test_un_genre_de_ligne_inconnu_est_refuse(): void
    {
        $reponse = $this->ajouter(['kind' => 'n_importe_quoi', 'id' => '1']);

        $this->assertContains($reponse->status, [400, 404, 422]);
    }

    public function test_un_identifiant_non_numerique_est_refuse(): void
    {
        $reponse = $this->ajouter(['kind' => 'original', 'id' => "1; DROP TABLE carts"]);

        $this->assertContains($reponse->status, [400, 404, 422]);
        $this->assertNotNull($this->valeur('SELECT COUNT(*) FROM carts'));
    }

    public function test_l_ajout_en_fetch_repond_en_json(): void
    {
        // 03-boutique §2 : « en JS, requete fetch qui met a jour la pastille ».
        $artwork = $this->oeuvre();

        $reponse = $this->requete('POST', '/cedric-taldu/fr/panier/ajout', server: [
            'HTTP_X_REQUESTED_WITH' => 'fetch',
            'HTTP_' . str_replace('-', '_', strtoupper(Csrf::HEADER)) => $this->jeton(),
            'HTTP_ACCEPT' => 'application/json',
        ], post: [
            'kind' => 'original',
            'id' => (string) $artwork,
            Csrf::FIELD => $this->jeton(),
        ]);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('application/json', $reponse->header('Content-Type') ?? '');
        $donnees = json_decode($reponse->body, true);
        $this->assertIsArray($donnees);
        $this->assertSame(1, $donnees['count'] ?? null);
    }

    // ------------------------------------------------------------ assistance

    private function oeuvre(int $prix = 45000, bool $vendue = false): int
    {
        $factory = (new ArtworkFactory($this->pdo))->published()
            ->priced($prix)
            ->translated('fr', 'articulation', 'Articulation');

        if ($vendue) {
            $factory = $factory->sold()->priced($prix);
        } else {
            $factory = $factory->available();
        }

        return $factory->create($this->categoryId);
    }

    private function reproduction(int $stock = 5): int
    {
        $artwork = $this->oeuvre();

        $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, is_published, created_at, updated_at)
             VALUES (:art, :kind, 1, NOW(), NOW())'
        )->execute(['art' => $artwork, 'kind' => 'standard']);
        $product = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :l, :t)'
        )->execute(['id' => $product, 'l' => 'fr', 't' => 'Tirage d’art']);

        $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           created_at, updated_at)
             VALUES (:prod, :sku, :size, 6000, :stock, 300, NOW(), NOW())'
        )->execute([
            'prod' => $product,
            'sku' => 'ART-' . bin2hex(random_bytes(4)),
            'size' => '30 × 40 cm',
            'stock' => $stock,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function jeton(): string
    {
        $jeton = $this->session->get(Csrf::SESSION_KEY);

        if (!is_string($jeton) || $jeton === '') {
            $jeton = str_repeat('a', 64);
            $this->session->set(Csrf::SESSION_KEY, $jeton);
        }

        return $jeton;
    }

    /**
     * @param array<string, string> $post
     */
    private function ajouter(array $post): Response
    {
        return $this->requete('POST', '/cedric-taldu/fr/panier/ajout', post: [
            ...$post,
            Csrf::FIELD => $this->jeton(),
        ]);
    }

    /**
     * @param array<string, string> $post
     */
    private function postPanier(string $uri, array $post, string $cookie): Response
    {
        return $this->requete('POST', $uri, cookies: [self::COOKIE => $cookie], post: [
            ...$post,
            Csrf::FIELD => $this->jeton(),
        ]);
    }

    private function cookieApres(Response $reponse): string
    {
        foreach ($reponse->cookies as $cookie) {
            if ($cookie->name === self::COOKIE) {
                return $cookie->value;
            }
        }

        return '';
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
