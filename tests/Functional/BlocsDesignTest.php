<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\MediaFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Blocs à contenu ET à design (retours du 2026-09-25) : bannière (hero) avec
 * image de fond, texte + image, section à fond coloré ou imagé, colonnes à
 * proportions. Le design passe par des classes en liste blanche : la CSP
 * interdit l'attribut style, et aucune valeur libre n'atteint le HTML.
 */
final class BlocsDesignTest extends FunctionalTestCase
{
    public function test_une_banniere_porte_son_image_de_fond_son_titre_et_son_bouton(): void
    {
        $media = (new MediaFactory($this->pdo))->named('atelier-lumiere')->translated('fr', 'L’atelier')->create();
        $this->composerPage([[
            'type' => 'hero',
            'props' => [
                'media' => (string) $media,
                'title' => 'Dessins à l’encre',
                'text' => '<p>Nouvelle série.</p>',
                'buttonLabel' => 'Voir la série',
                'buttonUrl' => '/fr/galerie',
                'height' => 'large',
                'align' => 'left',
                'overlay' => 'dark',
                'tone' => 'light',
                'titleLevel' => '2',
            ],
        ]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('class="bloc bloc-hero bloc-hero--large bloc-align--left bloc-ton--light bloc-voile--dark"', $corps);
        $this->assertMatchesRegularExpression('~<div class="bloc-fond">\s*<div class="dessin[^"]*">\s*<picture~', $corps);
        $this->assertStringContainsString('atelier-lumiere', $corps);
        $this->assertStringContainsString('<h2 class="bloc-hero-titre">Dessins à l’encre</h2>', $corps);
        $this->assertStringContainsString('<p>Nouvelle série.</p>', $corps);
        $this->assertStringContainsString('href="/cedric-taldu/fr/galerie">Voir la série</a>', $corps);
    }

    public function test_une_valeur_de_design_hors_liste_retombe_au_defaut(): void
    {
        $this->composerPage([[
            'type' => 'hero',
            'props' => ['title' => 'T', 'height' => 'evil" onload="x', 'align' => 'nulle', 'titleLevel' => '7'],
        ]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('bloc-hero--medium bloc-align--center', $corps);
        $this->assertStringContainsString('<h2 class="bloc-hero-titre">T</h2>', $corps);
        $this->assertStringNotContainsString('onload', $corps);
    }

    public function test_une_section_prend_un_fond_une_image_et_un_ton(): void
    {
        $media = (new MediaFactory($this->pdo))->named('mur-atelier')->translated('fr', 'Le mur')->create();
        $this->composerPage([[
            'type' => 'section',
            'props' => ['background' => 'dark', 'backgroundMedia' => (string) $media, 'overlay' => 'dark', 'tone' => 'light', 'align' => 'center'],
            'children' => [['type' => 'heading', 'props' => ['text' => 'Dans la section', 'level' => '2']]],
        ]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('bloc-couleur--dark bloc-ton--light bloc-align--center bloc-voile--dark', $corps);
        $this->assertStringContainsString('mur-atelier', $corps);
        $this->assertStringContainsString('Dans la section', $corps);
    }

    public function test_texte_et_image_cote_a_cote_dans_la_proportion_choisie(): void
    {
        $media = (new MediaFactory($this->pdo))->named('portrait')->translated('fr', 'Portrait')->create();
        $this->composerPage([[
            'type' => 'media-text',
            'props' => ['media' => (string) $media, 'content' => '<p>À côté.</p>', 'position' => 'right', 'ratio' => '1-2'],
        ]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('class="bloc bloc-media-texte bloc-media-texte--right bloc-ratio--1-2"', $corps);
        $this->assertStringContainsString('portrait', $corps);
        $this->assertStringContainsString('<p>À côté.</p>', $corps);
    }

    public function test_des_colonnes_a_deux_tiers_un_tiers(): void
    {
        $this->composerPage([[
            'type' => 'columns',
            'props' => ['count' => '2', 'ratio' => '2-1'],
            'children' => [
                ['type' => 'text', 'props' => ['content' => '<p>Large</p>']],
                ['type' => 'text', 'props' => ['content' => '<p>Étroite</p>']],
            ],
        ]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('bloc-colonnes bloc-colonnes--2 bloc-gap--md bloc-ratio--2-1', $corps);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function composerPage(array $blocks): void
    {
        $this->pdo->prepare(
            "UPDATE page_translations SET blocks = :b
              WHERE locale = 'fr' AND page_id = (SELECT id FROM pages WHERE code = 'about')"
        )->execute(['b' => json_encode($blocks, JSON_THROW_ON_ERROR)]);
    }
}
