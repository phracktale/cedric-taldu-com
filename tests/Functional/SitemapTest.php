<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\PostFactory;
use Tests\Support\FunctionalTestCase;

/**
 * sitemap.xml (05-i18n-seo §5).
 *
 * Accueil, rubriques, œuvres, articles et pages, dans les deux langues, avec
 * les liens alternatifs. URL absolues depuis APP_URL. Panier, tunnel et admin
 * exclus.
 */
final class SitemapTest extends FunctionalTestCase
{
    public function test_le_sitemap_repond_en_xml(): void
    {
        $reponse = $this->get('/cedric-taldu/sitemap.xml');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('application/xml', (string) $reponse->header('Content-Type'));
        $this->assertStringContainsString('<urlset', $reponse->body);
        $this->assertStringContainsString('<?xml', $reponse->body);
    }

    public function test_il_liste_les_entites_publiees_dans_les_deux_langues(): void
    {
        $categorie = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->translated('en', 'inks', 'Inks')->create();
        (new ArtworkFactory($this->pdo))->available()
            ->translated('fr', 'articulation', 'Articulation')
            ->translated('en', 'articulation-en', 'Articulation')->create($categorie);
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'expo', 'Expo')->translated('en', 'show', 'Show')->create();

        $corps = $this->get('/cedric-taldu/sitemap.xml')->body;

        // Rubrique dans les deux langues (segments et slugs traduits).
        $this->assertStringContainsString('/fr/galerie/encres', $corps);
        $this->assertStringContainsString('/en/gallery/inks', $corps);
        // Œuvre et article.
        $this->assertStringContainsString('/fr/oeuvre/articulation', $corps);
        $this->assertStringContainsString('/en/artwork/articulation-en', $corps);
        $this->assertStringContainsString('/fr/actus/expo', $corps);
        $this->assertStringContainsString('/en/news/show', $corps);
        // Liens alternatifs.
        $this->assertStringContainsString('xhtml:link', $corps);
        $this->assertStringContainsString('hreflang="x-default"', $corps);
        // URL absolues (APP_URL de test).
        $this->assertStringContainsString('https://customer.phracktale.com', $corps);
    }

    public function test_les_pages_fixes_et_l_accueil_y_figurent(): void
    {
        $corps = $this->get('/cedric-taldu/sitemap.xml')->body;

        $this->assertStringContainsString('/fr/mentions-legales', $corps);
        $this->assertStringContainsString('/en/legal-notice', $corps);
        $this->assertStringContainsString('/fr/</loc>', $corps);
    }

    public function test_un_brouillon_n_y_figure_pas(): void
    {
        $categorie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->draft()->translated('fr', 'cachee', 'Cachée')->create($categorie);
        (new PostFactory($this->pdo))->draft()->translated('fr', 'secret', 'Secret')->create();

        $corps = $this->get('/cedric-taldu/sitemap.xml')->body;

        $this->assertStringNotContainsString('/oeuvre/cachee', $corps);
        $this->assertStringNotContainsString('/actus/secret', $corps);
    }

    public function test_ni_le_panier_ni_l_admin_n_y_figurent(): void
    {
        $corps = $this->get('/cedric-taldu/sitemap.xml')->body;

        $this->assertStringNotContainsString('/panier', $corps);
        $this->assertStringNotContainsString('/admin', $corps);
        $this->assertStringNotContainsString('/commande', $corps);
    }
}
