<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use App\Core\Response;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * 03-boutique §2 : l'en-tête porte un accès permanent au panier et une pastille
 * du nombre d'articles.
 *
 * Sans ce lien, l'ajout au panier en fetch (cart.js) réussissait mais ne
 * mettait à jour aucune cible : le bouton « Acquérir » semblait ne rien faire.
 * La pastille se lit à chaque page SANS jamais créer de panier.
 */
final class EntetePanierTest extends FunctionalTestCase
{
    private const COOKIE = 'ct_cart';

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = (new CategoryFactory($this->pdo))
            ->translated('fr', 'encres', 'Encres')
            ->published()
            ->create();
    }

    public function test_l_entete_porte_un_lien_vers_le_panier(): void
    {
        $reponse = $this->requete('GET', '/cedric-taldu/fr/');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('/fr/panier', $reponse->body);
        $this->assertStringContainsString('data-cart-count', $reponse->body);
    }

    public function test_la_pastille_est_masquee_quand_le_panier_est_vide(): void
    {
        $reponse = $this->requete('GET', '/cedric-taldu/fr/');

        // La pastille porte l'attribut `hidden` tant qu'aucun article n'y est.
        $this->assertMatchesRegularExpression('/data-cart-count[^>]*\bhidden\b/', $reponse->body);
    }

    public function test_la_pastille_compte_les_articles_du_panier(): void
    {
        $oeuvre = (new ArtworkFactory($this->pdo))
            ->published()->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')
            ->create($this->categoryId);

        $cookie = $this->cookieApres($this->ajouter(['kind' => 'original', 'id' => (string) $oeuvre]));

        $reponse = $this->requete('GET', '/cedric-taldu/fr/', cookies: [self::COOKIE => $cookie]);

        // La pastille affiche « 1 » et n'est plus masquée.
        $this->assertMatchesRegularExpression('/data-cart-count(?![^>]*\bhidden\b)[^>]*>1</', $reponse->body);
    }

    public function test_la_fiche_oeuvre_porte_une_confirmation_d_ajout(): void
    {
        (new ArtworkFactory($this->pdo))
            ->published()->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')
            ->create($this->categoryId);

        $reponse = $this->requete('GET', '/cedric-taldu/fr/oeuvre/articulation');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('data-cart-confirm', $reponse->body);
    }

    // --------------------------------------------------------------- outils

    /**
     * @param array<string, string> $post
     */
    private function ajouter(array $post): Response
    {
        $jeton = $this->session->get(Csrf::SESSION_KEY);

        if (!is_string($jeton) || $jeton === '') {
            $jeton = str_repeat('a', 64);
            $this->session->set(Csrf::SESSION_KEY, $jeton);
        }

        return $this->requete('POST', '/cedric-taldu/fr/panier/ajout', post: [
            ...$post,
            Csrf::FIELD => $jeton,
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
}
