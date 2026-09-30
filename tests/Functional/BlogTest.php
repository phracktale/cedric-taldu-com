<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;
use Tests\Support\Factory\PostFactory;

/**
 * Blog public « Actus » (02-front §6, critère du lot 4 : « l'artiste publie un
 * article »). On prouve ici le versant LECTURE ; la publication depuis le
 * back-office est éprouvée par le test d'administration.
 */
final class BlogTest extends FunctionalTestCase
{
    public function test_la_liste_des_actus_montre_les_articles_publies(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'mon-expo', 'Mon exposition', 'Un aperçu du vernissage.')->create();

        $response = $this->get('/cedric-taldu/fr/actus');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Mon exposition', $response->body);
        $this->assertStringContainsString('Un aperçu du vernissage.', $response->body);
    }

    public function test_un_brouillon_n_apparait_pas_dans_la_liste(): void
    {
        (new PostFactory($this->pdo))->draft()->translated('fr', 'secret', 'Article secret')->create();

        $response = $this->get('/cedric-taldu/fr/actus');

        $this->assertSame(200, $response->status);
        $this->assertStringNotContainsString('Article secret', $response->body);
    }

    public function test_un_article_publie_est_lisible(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'mon-expo', 'Mon exposition', 'Aperçu', '<p>Le corps de l’article.</p>')
            ->create();

        $response = $this->get('/cedric-taldu/fr/actus/mon-expo');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Mon exposition', $response->body);
        $this->assertStringContainsString('Le corps de l’article.', $response->body);
    }

    public function test_une_exposition_montre_sa_periode_son_adresse_son_lien_et_sa_description(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->event('2026-10-12', 'Galerie du Beffroi')
            ->exposition('2026-11-20', '3 rue des Sergents, 80000 Amiens', 'https://galerie.example/traits')
            ->translated('fr', 'traits', 'Traits')
            ->eventDescription('fr', 'Encres récentes.')
            ->create();

        $corps = $this->get('/cedric-taldu/fr/actus/traits')->body;

        $this->assertStringContainsString('du 12 octobre au 20 novembre 2026', $corps);
        $this->assertStringContainsString('Galerie du Beffroi', $corps);
        $this->assertStringContainsString('3 rue des Sergents, 80000 Amiens', $corps);
        $this->assertStringContainsString('href="https://galerie.example/traits"', $corps);
        $this->assertStringContainsString('rel="noopener', $corps);
        $this->assertStringContainsString('Encres récentes.', $corps);
    }

    public function test_la_liste_montre_la_periode_d_une_exposition(): void
    {
        (new PostFactory($this->pdo))->event('2026-10-12', 'Amiens')->exposition('2026-10-20')
            ->translated('fr', 'traits', 'Traits')->create();

        $this->assertStringContainsString('du 12 au 20 octobre 2026', $this->get('/cedric-taldu/fr/actus')->body);
    }

    public function test_l_article_porte_son_canonique(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'mon-expo', 'Mon exposition')->create();

        $corps = $this->get('/cedric-taldu/fr/actus/mon-expo')->body;

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://customer.phracktale.com/cedric-taldu/fr/actus/mon-expo">',
            $corps,
        );
    }

    public function test_un_slug_inconnu_renvoie_404(): void
    {
        $response = $this->get('/cedric-taldu/fr/actus/inexistant');

        $this->assertSame(404, $response->status);
    }

    public function test_un_brouillon_n_est_pas_accessible_par_son_url(): void
    {
        // Pas d'énumération : un article non publié est introuvable, pas interdit.
        (new PostFactory($this->pdo))->draft()->translated('fr', 'cache', 'Caché')->create();

        $response = $this->get('/cedric-taldu/fr/actus/cache');

        $this->assertSame(404, $response->status);
    }
}
