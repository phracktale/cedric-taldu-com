<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\PostFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Modèles de contenu composés par glisser-déposer (retours du 2026-09-25).
 */
final class ModelesAdminTest extends AdminTestCase
{
    private const ADMIN = '/cedric-taldu/admin/templates';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_propose_un_modele_a_composer_par_type(): void
    {
        $corps = $this->requete('GET', self::ADMIN)->body;

        $this->assertStringContainsString('href="' . self::ADMIN . '"', $corps);
        foreach (['page', 'post', 'category', 'contact', 'artwork'] as $type) {
            $this->assertStringContainsString('name="template_' . $type . '"', $corps);
        }
        $this->assertSame(5, substr_count($corps, 'data-composer-zone'));
    }

    public function test_le_modele_d_actu_pilote_les_articles(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'vernissage', 'Vernissage', body: '<p>Le corps.</p>')->create();

        $this->enregistrer(['template_post' => [['type' => 'body'], ['type' => 'header']]]);

        $corps = $this->requete('GET', '/cedric-taldu/fr/actus/vernissage')->body;
        $this->assertStringContainsString('Le corps.', $corps);
        $this->assertLessThan(strpos($corps, '<h1'), strpos($corps, 'Le corps.'));
        $this->assertStringNotContainsString('article-retour', $corps);
        $this->assertStringNotContainsString('<nav class="fil"', $corps);
    }

    public function test_une_section_obligatoire_ne_peut_pas_disparaitre(): void
    {
        $this->enregistrer(['template_contact' => [['type' => 'rgpd']]]);

        $corps = $this->requete('GET', '/cedric-taldu/fr/contact')->body;
        $this->assertStringContainsString('class="contact-form"', $corps);
        $this->assertStringContainsString('<h1>', $corps);
    }

    public function test_le_modele_d_oeuvre_et_de_galerie_s_appliquent(): void
    {
        $galerie = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres', null, null, '<p>Méthode du point.</p>')->create();
        (new ArtworkFactory($this->pdo))->translated('fr', 'pilier', 'Pilier')->create($galerie);

        $this->enregistrer([
            'template_artwork' => [['type' => 'main']],
            'template_category' => [['type' => 'head'], ['type' => 'grid']],
        ]);

        $oeuvre = $this->requete('GET', '/cedric-taldu/fr/oeuvre/pilier')->body;
        $this->assertStringNotContainsString('<nav class="fil"', $oeuvre);
        $this->assertStringContainsString('class="fiche wrap"', $oeuvre);

        $this->assertStringNotContainsString('Méthode du point.', $this->requete('GET', '/cedric-taldu/fr/galerie/encres')->body);
    }

    public function test_l_enregistrement_sans_jeton_csrf_est_refuse(): void
    {
        $this->assertContains($this->requete('POST', self::ADMIN, post: ['template_page' => '[]'])->status, [403, 419]);
    }

    /**
     * @param array<string, list<array{type: string}>> $modeles
     */
    private function enregistrer(array $modeles): void
    {
        $post = [];
        foreach ($modeles as $champ => $liste) {
            $post[$champ] = json_encode($liste, JSON_THROW_ON_ERROR);
        }

        $this->assertSame(302, $this->postAvecJeton(self::ADMIN, $post)->status);
    }
}
