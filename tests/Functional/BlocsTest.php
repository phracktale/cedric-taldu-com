<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\PostFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Composition par blocs (revue du 2026-09-24, incrément 2).
 *
 * Les blocs ne remplacent plus le corps : ils s'ajoutent après lui. Le bouton
 * devient un vrai CTA (lien interne préfixé, alignement) et l'image se choisit
 * dans la médiathèque.
 */
final class BlocsTest extends FunctionalTestCase
{
    public function test_le_corps_et_les_blocs_d_une_page_s_affichent_ensemble(): void
    {
        $this->pdo->exec(
            "UPDATE page_translations SET body = '<p>Texte historique.</p>'
              WHERE locale = 'fr' AND page_id = (SELECT id FROM pages WHERE code = 'about')"
        );
        $this->composerPage([['type' => 'heading', 'props' => ['text' => 'Parcours', 'level' => '2']]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('Texte historique.', $corps);
        $this->assertStringContainsString('Parcours', $corps);
        $this->assertLessThan(strpos($corps, 'Parcours'), strpos($corps, 'Texte historique.'));
    }

    public function test_un_lien_interne_de_bouton_recoit_le_prefixe_de_chemin(): void
    {
        // CLAUDE.md : aucune URL en dur. Sans préfixe, le lien casse en préprod.
        $this->composerPage([['type' => 'button', 'props' => ['label' => 'Livret', 'url' => '/fr/livret']]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('href="/cedric-taldu/fr/livret"', $corps);
    }

    public function test_un_bouton_s_aligne_comme_un_cta(): void
    {
        $this->composerPage([['type' => 'button', 'props' => ['label' => 'Livret', 'url' => '/fr/livret', 'align' => 'right']]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('cta-row--droite', $corps);
    }

    public function test_un_lien_en_protocole_relatif_est_neutralise(): void
    {
        // « //evil.example » commence par « / » mais sort du site.
        $this->composerPage([['type' => 'button', 'props' => ['label' => 'Piège', 'url' => '//evil.example/x']]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringNotContainsString('evil.example', $corps);
    }

    public function test_un_bloc_image_de_la_mediatheque_rend_une_picture(): void
    {
        $media = (new MediaFactory($this->pdo))->named('atelier-lumiere')->translated('fr', 'L’atelier')->create();
        $this->composerPage([['type' => 'image', 'props' => ['media' => (string) $media, 'caption' => 'Mon atelier']]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertMatchesRegularExpression('~<figure class="bloc bloc-image">\s*<div class="dessin[^"]*">\s*<picture~', $corps);
        $this->assertStringContainsString('atelier-lumiere', $corps);
        $this->assertStringContainsString('Mon atelier', $corps);
    }

    public function test_un_bloc_image_dont_le_media_a_disparu_ne_rend_rien(): void
    {
        $this->composerPage([['type' => 'image', 'props' => ['media' => '999999']]]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringNotContainsString('bloc-image', $corps);
    }

    public function test_un_article_rend_ses_blocs_apres_son_corps(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'vernissage', 'Vernissage', '<p>Le corps.</p>')->create();
        $this->pdo->prepare("UPDATE post_translations SET blocks = :b WHERE slug = 'vernissage'")->execute([
            'b' => json_encode([['type' => 'quote', 'props' => ['text' => 'Une citation']]], JSON_THROW_ON_ERROR),
        ]);

        $corps = $this->get('/cedric-taldu/fr/actus/vernissage')->body;

        $this->assertStringContainsString('bloc-citation', $corps);
        $this->assertLessThan(strpos($corps, 'Une citation'), strpos($corps, 'Le corps.'));
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
