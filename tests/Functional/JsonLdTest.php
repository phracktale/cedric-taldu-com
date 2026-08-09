<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\PostFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Données structurées JSON-LD dans les pages (05-i18n-seo §5).
 *
 * Le JSON-LD est produit par json_encode + JSON_HEX_* : un titre contenant
 * « </script> » ne doit jamais pouvoir refermer la balise. Chaque script porte
 * le nonce de la CSP stricte.
 */
final class JsonLdTest extends FunctionalTestCase
{
    public function test_l_accueil_porte_person_et_website(): void
    {
        $corps = $this->get('/cedric-taldu/fr/')->body;

        $this->assertStringContainsString('application/ld+json', $corps);
        $this->assertStringContainsString('"@type":"Person"', $corps);
        $this->assertStringContainsString('"@type":"WebSite"', $corps);
    }

    public function test_la_fiche_oeuvre_porte_un_product_et_un_fil_d_ariane(): void
    {
        $categorie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')->create($categorie);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringContainsString('"@type":"Product"', $corps);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $corps);
        $this->assertStringContainsString('schema.org/InStock', $corps);
    }

    public function test_le_product_porte_une_image_absolue_quand_l_oeuvre_en_a_une(): void
    {
        // Google recommande une image sur les pages Product : le visuel principal,
        // en URL absolue.
        $categorie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        $media = (new MediaFactory($this->pdo))->sized(2400, 1600)->translated('fr', 'Articulation')->create();
        (new ArtworkFactory($this->pdo))->available()->priced(45000)->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($categorie);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertMatchesRegularExpression('#"image":"https?://[^"]+/media/#', $corps);
    }

    public function test_l_article_date_porte_un_evenement(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->event('2026-06-10', 'Amiens')
            ->translated('fr', 'expo', 'Vernissage')->create();

        $corps = $this->get('/cedric-taldu/fr/actus/expo')->body;

        $this->assertStringContainsString('"@type":"Event"', $corps);
    }

    public function test_la_rubrique_porte_une_collectionpage(): void
    {
        $categorie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->available()->translated('fr', 'a', 'A')->create($categorie);

        $corps = $this->get('/cedric-taldu/fr/galerie/encres')->body;

        $this->assertStringContainsString('"@type":"CollectionPage"', $corps);
    }

    public function test_un_titre_hostile_ne_casse_pas_la_balise_script(): void
    {
        // Un slug donne l'URL ; le TITRE est libre. On y glisse une fermeture de
        // balise : json_encode + JSON_HEX_TAG doit la neutraliser.
        $categorie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->available()->priced(45000)
            ->translated('fr', 'articulation', 'Fin</script><script>alert(1)</script>')
            ->create($categorie);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringNotContainsString('</script><script>alert(1)', $corps);
        $this->assertStringContainsString('</script', $corps);
    }
}
