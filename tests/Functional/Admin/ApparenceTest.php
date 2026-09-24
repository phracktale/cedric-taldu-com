<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\PostFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Écran « Apparence » (revue du 2026-09-24) : style de l'entrée de menu active
 * et bouton d'appel à l'action en fin d'actualité.
 */
final class ApparenceTest extends AdminTestCase
{
    private const ADMIN = '/cedric-taldu/admin/apparence';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_apparence_est_dans_le_menu_et_propose_les_styles(): void
    {
        $reponse = $this->requete('GET', self::ADMIN);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('href="' . self::ADMIN . '"', $reponse->body);
        foreach (['souligne', 'gras', 'inverse', 'couleur'] as $style) {
            $this->assertStringContainsString('name="style" value="' . $style . '"', $reponse->body);
        }
        $this->assertStringContainsString('name="couleur"', $reponse->body);
    }

    public function test_le_style_et_la_couleur_choisis_s_appliquent_au_site(): void
    {
        $reponse = $this->postAvecJeton(self::ADMIN, ['style' => 'couleur', 'couleur' => '#AA3300']);

        $this->assertSame(302, $reponse->status);
        $corps = $this->requete('GET', '/cedric-taldu/fr/a-propos')->body;
        $this->assertStringContainsString('data-actif="couleur"', $corps);
        $this->assertMatchesRegularExpression('~<style nonce="[^"]+">[^<]*--actif: #aa3300~', $corps);
    }

    public function test_une_couleur_invalide_n_atteint_pas_la_feuille_de_style(): void
    {
        $this->postAvecJeton(self::ADMIN, ['style' => 'gras', 'couleur' => 'red}body{display:none']);

        $corps = $this->requete('GET', '/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('data-actif="gras"', $corps);
        $this->assertStringNotContainsString('display:none', $corps);
    }

    public function test_le_facteur_de_zoom_des_vignettes_est_reglable_et_borne(): void
    {
        $this->postAvecJeton(self::ADMIN, ['style' => 'souligne', 'zoom' => '85']);
        $corps = $this->requete('GET', '/cedric-taldu/fr/a-propos')->body;
        $this->assertMatchesRegularExpression('~<style nonce="[^"]+">[^<]*--vignette-zoom: 0\.85~', $corps);

        // Hors bornes (60 à 100 %) : ramené dans l'intervalle.
        $this->postAvecJeton(self::ADMIN, ['style' => 'souligne', 'zoom' => '5000']);
        $corps = $this->requete('GET', '/cedric-taldu/fr/a-propos')->body;
        $this->assertStringContainsString('--vignette-zoom: 1;', $corps);
    }

    public function test_sans_reglage_aucun_cta_ne_clot_les_actus(): void
    {
        $this->article();

        $corps = $this->requete('GET', '/cedric-taldu/fr/actus/vernissage')->body;

        $this->assertStringNotContainsString('article-cta', $corps);
    }

    public function test_le_cta_de_fin_d_actualite_est_parametrable(): void
    {
        $this->article();

        $this->postAvecJeton(self::ADMIN, [
            'style' => 'souligne',
            'cta_affiche' => '1',
            'cta_fr' => 'Découvrir les œuvres',
            'cta_target' => 'contact',
        ]);

        $corps = $this->requete('GET', '/cedric-taldu/fr/actus/vernissage')->body;
        $this->assertStringContainsString('article-cta', $corps);
        $this->assertMatchesRegularExpression('~href="/cedric-taldu/fr/contact"[^>]*>\s*Découvrir les œuvres~', $corps);
        // Après le corps de l'article.
        $this->assertGreaterThan(strpos($corps, 'article-corps'), strpos($corps, 'article-cta'));
    }

    public function test_l_enregistrement_sans_jeton_csrf_est_refuse(): void
    {
        $reponse = $this->requete('POST', self::ADMIN, post: ['style' => 'gras']);

        $this->assertContains($reponse->status, [403, 419]);
    }

    private function article(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'vernissage', 'Vernissage', '<p>Le texte.</p>')->create();
    }
}
