<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FunctionalTestCase;

/**
 * Pages éditoriales à code fixe (02-front §6).
 *
 * Les cinq pages sont posées par la migration 0007 : elles existent dans tout
 * environnement, mentions légales et CGV comprises, sans jeu de démonstration.
 */
final class PagesTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pagesFr(): iterable
    {
        yield 'à propos' => ['/cedric-taldu/fr/a-propos', 'À propos'];
        yield 'livret' => ['/cedric-taldu/fr/livret', 'Livret'];
        yield 'mentions légales' => ['/cedric-taldu/fr/mentions-legales', 'Mentions légales'];
        yield 'confidentialité' => ['/cedric-taldu/fr/confidentialite', 'Confidentialité'];
        yield 'CGV' => ['/cedric-taldu/fr/conditions-generales-de-vente', 'Conditions générales de vente'];
    }

    #[DataProvider('pagesFr')]
    public function test_chaque_page_fixe_repond_200_avec_son_titre(string $uri, string $titre): void
    {
        $reponse = $this->get($uri);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('<h1>' . $titre . '</h1>', $reponse->body);
    }

    public function test_une_page_composee_de_blocs_rend_les_blocs(): void
    {
        $this->composer('about', [
            ['type' => 'heading', 'props' => ['text' => 'Mon parcours', 'level' => '2']],
            ['type' => 'text', 'props' => ['content' => '<p>Texte de présentation.</p>']],
            ['type' => 'quote', 'props' => ['text' => 'Une citation', 'author' => 'Cédric']],
            ['type' => 'button', 'props' => ['label' => 'Me contacter', 'url' => '/fr/contact']],
        ]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('class="bloc bloc-titre">Mon parcours</h2>', $corps);
        $this->assertStringContainsString('Texte de présentation.', $corps);
        $this->assertStringContainsString('bloc-citation', $corps);
        $this->assertStringContainsString('href="/fr/contact"', $corps);
    }

    public function test_un_bloc_colonnes_rend_ses_enfants(): void
    {
        $this->composer('about', [
            ['type' => 'columns', 'props' => ['count' => '2'], 'children' => [
                ['type' => 'text', 'props' => ['content' => '<p>Gauche</p>']],
                ['type' => 'text', 'props' => ['content' => '<p>Droite</p>']],
            ]],
        ]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('bloc-colonnes--2', $corps);
        $this->assertStringContainsString('Gauche', $corps);
        $this->assertStringContainsString('Droite', $corps);
    }

    public function test_une_url_de_bouton_hostile_est_neutralisee(): void
    {
        // Un href « javascript: » est ramené à un lien inerte (#).
        $this->composer('about', [
            ['type' => 'button', 'props' => ['label' => 'Piège', 'url' => 'javascript:alert(1)']],
        ]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringNotContainsString('javascript:alert(1)', $corps);
        $this->assertStringContainsString('href="#"', $corps);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function composer(string $code, array $blocks): void
    {
        $this->pdo->prepare(
            "UPDATE page_translations SET blocks = :b
              WHERE locale = 'fr' AND page_id = (SELECT id FROM pages WHERE code = :code)"
        )->execute(['b' => json_encode($blocks, JSON_THROW_ON_ERROR), 'code' => $code]);
    }

    public function test_la_page_anglaise_repond_aussi(): void
    {
        $reponse = $this->get('/cedric-taldu/en/about');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('About', $reponse->body);
    }

    public function test_une_page_porte_son_canonique_et_ses_hreflang(): void
    {
        $corps = $this->get('/cedric-taldu/fr/mentions-legales')->body;

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://customer.phracktale.com/cedric-taldu/fr/mentions-legales">',
            $corps,
        );
        $this->assertStringContainsString('hreflang="en"', $corps);
        $this->assertStringContainsString('hreflang="x-default"', $corps);
    }

    public function test_une_page_depubliee_repond_404(): void
    {
        // Pas d'énumération : une page dépubliée est introuvable, pas interdite.
        $this->pdo->exec("UPDATE pages SET is_published = 0 WHERE code = 'about'");

        $this->assertSame(404, $this->get('/cedric-taldu/fr/a-propos')->status);
    }

    // ------------------------------------------------------------------ CGV

    public function test_la_page_cgv_porte_le_texte_integral_en_francais(): void
    {
        // Migration 0009 : le corps n'est plus un texte d'attente mais les CGV
        // complètes. On vérifie quelques ancres réparties dans le document.
        $corps = $this->get('/cedric-taldu/fr/conditions-generales-de-vente')->body;

        $this->assertStringContainsString('Article 1', $corps);
        $this->assertStringContainsString('Droit de rétractation', $corps);
        $this->assertStringContainsString('médiation', $corps);
        $this->assertStringNotContainsString('À compléter en back-office', $corps);
    }

    public function test_la_page_cgv_est_traduite_en_anglais(): void
    {
        $corps = $this->get('/cedric-taldu/en/terms')->body;

        $this->assertStringContainsString('Right of withdrawal', $corps);
        $this->assertStringNotContainsString('To be completed in the back office', $corps);
    }

    public function test_la_page_cgv_propose_le_pdf_dans_la_bonne_langue(): void
    {
        $fr = $this->get('/cedric-taldu/fr/conditions-generales-de-vente')->body;
        $en = $this->get('/cedric-taldu/en/terms')->body;

        $this->assertStringContainsString('documents/cgv-cedric-taldu-fr.pdf', $fr);
        $this->assertStringContainsString('documents/cgv-cedric-taldu-en.pdf', $en);
    }

    public function test_les_autres_pages_fixes_n_affichent_pas_le_lien_cgv_pdf(): void
    {
        // Le lien PDF est réservé aux CGV : une autre page fixe ne le porte pas.
        $this->assertStringNotContainsString(
            'documents/cgv-cedric-taldu',
            $this->get('/cedric-taldu/fr/mentions-legales')->body,
        );
    }
}
