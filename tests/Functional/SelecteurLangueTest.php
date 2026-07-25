<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\PostFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Sélecteur de langue (05-i18n-seo §2).
 *
 * Il pointe vers l'URL ÉQUIVALENTE dans l'autre langue — segments et slugs
 * traduits — et non vers l'accueil. Il doit être présent sur toutes les pages,
 * y compris celles qui, avant le lot 5, le laissaient muet (blog, pages, contact).
 */
final class SelecteurLangueTest extends FunctionalTestCase
{
    public function test_une_page_editoriale_offre_le_lien_vers_l_anglais(): void
    {
        // Segment traduit : /fr/mentions-legales ↔ /en/legal-notice.
        $corps = $this->get('/cedric-taldu/fr/mentions-legales')->body;

        $this->assertStringContainsString('href="/cedric-taldu/en/legal-notice"', $corps);
        $this->assertStringContainsString('hreflang="en"', $corps);
    }

    public function test_la_liste_des_actus_offre_le_lien_vers_l_anglais(): void
    {
        $corps = $this->get('/cedric-taldu/fr/actus')->body;

        $this->assertStringContainsString('href="/cedric-taldu/en/news"', $corps);
    }

    public function test_l_article_pointe_vers_son_equivalent_traduit(): void
    {
        // Slug traduit par langue : le sélecteur suit la traduction.
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'mon-expo', 'Mon exposition')
            ->translated('en', 'my-show', 'My show')
            ->create();

        $corps = $this->get('/cedric-taldu/fr/actus/mon-expo')->body;

        $this->assertStringContainsString('href="/cedric-taldu/en/news/my-show"', $corps);
    }

    public function test_l_article_sans_traduction_anglaise_pointe_vers_le_slug_francais(): void
    {
        // Repli : le slug EN retombe sur le FR (05-i18n-seo §3).
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'sans-en', 'Sans anglais')->create();

        $corps = $this->get('/cedric-taldu/fr/actus/sans-en')->body;

        $this->assertStringContainsString('href="/cedric-taldu/en/news/sans-en"', $corps);
    }

    public function test_la_fiche_oeuvre_offre_le_lien_traduit(): void
    {
        $categorie = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->translated('en', 'inks', 'Inks')->create();
        (new ArtworkFactory($this->pdo))->available()
            ->translated('fr', 'articulation', 'Articulation')
            ->translated('en', 'articulation-en', 'Articulation')
            ->create($categorie);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringContainsString('href="/cedric-taldu/en/artwork/articulation-en"', $corps);
    }
}
