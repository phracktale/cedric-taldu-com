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

    public function test_la_page_des_actus_n_est_plus_une_bande_beige(): void
    {
        // Compte rendu client du 2026-10-12 : la liste portait la classe de la
        // section « Actualités » de l'accueil, donc son fond beige — et perdait
        // au passage les marges latérales de la page.
        (new PostFactory($this->pdo))->translated('fr', 'mon-expo', 'Mon exposition')->create();

        $corps = $this->get('/cedric-taldu/fr/actus')->body;

        $this->assertStringContainsString('<div class="wrap actus-page">', $corps);
        $this->assertStringNotContainsString('class="wrap actus"', $corps);
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
            ->exposition('2026-11-20', 'https://galerie.example/traits')
            ->adresse('3 rue des Sergents', '80000', 'Amiens')
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

    public function test_la_date_de_publication_s_affiche_a_part_des_dates_de_l_evenement(): void
    {
        // Retours Search Console du 2026-10-26 : « Publié le » pour l'article,
        // la période pour l'événement — deux dates qui ne se confondent plus.
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->event('2026-10-12', 'Galerie du Beffroi')->exposition('2026-11-20')
            ->translated('fr', 'traits', 'Traits')->translated('en', 'lines', 'Lines')->create();

        $fr = $this->get('/cedric-taldu/fr/actus/traits')->body;
        $en = $this->get('/cedric-taldu/en/news/lines')->body;

        $this->assertStringContainsString('Publié le 1 juin 2026', $fr);
        $this->assertStringContainsString('du 12 octobre au 20 novembre 2026', $fr);
        $this->assertStringContainsString('Published on June 1, 2026', $en);
    }

    public function test_un_billet_sans_evenement_affiche_sa_date_de_publication(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'note', 'Note d’atelier')->create();

        $this->assertStringContainsString('Publié le 1 juin 2026', $this->get('/cedric-taldu/fr/actus/note')->body);
    }

    public function test_un_evenement_annule_l_annonce(): void
    {
        (new PostFactory($this->pdo))->event('2026-10-12', 'Amiens')->statut('cancelled')
            ->translated('fr', 'traits', 'Traits')->translated('en', 'lines', 'Lines')->create();

        $this->assertStringContainsString('Événement annulé', $this->get('/cedric-taldu/fr/actus/traits')->body);
        $this->assertStringContainsString('Event cancelled', $this->get('/cedric-taldu/en/news/lines')->body);
    }

    public function test_l_organisateur_et_l_entree_s_affichent(): void
    {
        (new PostFactory($this->pdo))->event('2026-10-12', 'Verrière du parc André Citroën')
            ->organisateur('DF Art Project', 'https://df-artproject.com/')->entree(0)
            ->translated('fr', 'salon', 'Salon')->create();

        $corps = $this->get('/cedric-taldu/fr/actus/salon')->body;

        $this->assertStringContainsString('Organisé par <a href="https://df-artproject.com/" rel="noopener noreferrer" target="_blank">DF Art Project</a>', $corps);
        $this->assertStringContainsString('Entrée libre', $corps);
    }

    public function test_une_entree_payante_affiche_son_prix(): void
    {
        (new PostFactory($this->pdo))->event('2026-10-12', 'Amiens')->entree(550)
            ->translated('fr', 'salon', 'Salon')->create();

        $this->assertMatchesRegularExpression('~Entrée : 5,50[\s\x{00A0}\x{202F}]€~u', $this->get('/cedric-taldu/fr/actus/salon')->body);
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
