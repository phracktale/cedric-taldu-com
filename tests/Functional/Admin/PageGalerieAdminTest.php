<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Texte explicatif de la page Galerie (compte rendu client du 2026-10-12) :
 * « la page Galerie doit pouvoir contenir un texte explicatif avec Titre et
 * paragraphe ». Il se saisit depuis l'écran des galeries, en texte simple, par
 * langue ; sans lui, la page garde sa phrase d'introduction par défaut.
 */
final class PageGalerieAdminTest extends AdminTestCase
{
    private const ECRAN = '/cedric-taldu/admin/galeries/page';
    private const DEFAUT = 'Les œuvres, rassemblées par technique et par recherche.';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_s_ouvre_depuis_la_liste_des_galeries(): void
    {
        $this->assertStringContainsString(
            'href="' . self::ECRAN . '"',
            $this->requete('GET', '/cedric-taldu/admin/galeries')->body,
        );

        $ecran = $this->requete('GET', self::ECRAN);
        $this->assertSame(200, $ecran->status);
        foreach (['titre_fr', 'texte_fr', 'titre_en', 'texte_en'] as $champ) {
            $this->assertStringContainsString('name="' . $champ . '"', $ecran->body);
        }
    }

    public function test_sans_texte_la_page_garde_son_introduction_par_defaut(): void
    {
        $this->assertStringContainsString(self::DEFAUT, $this->requete('GET', '/cedric-taldu/fr/galerie')->body);
    }

    public function test_le_titre_et_les_paragraphes_s_affichent_sur_la_page_galerie(): void
    {
        $reponse = $this->postAvecJeton(self::ECRAN, [
            'titre_fr' => 'Trois corpus, une recherche',
            'texte_fr' => "Le dessin avance point par point.\r\n\r\nLa peinture engage le corps autrement,\r\npar la matière.",
            'titre_en' => '',
            'texte_en' => '',
        ]);
        $this->assertContains($reponse->status, [302, 303]);

        $page = $this->requete('GET', '/cedric-taldu/fr/galerie')->body;
        $this->assertStringContainsString('<h1>Galerie</h1>', $page);
        $this->assertStringContainsString('<h2>Trois corpus, une recherche</h2>', $page);
        $this->assertStringContainsString('<p>Le dessin avance point par point.</p>', $page);
        $this->assertStringContainsString('<p>La peinture engage le corps autrement,<br>par la matière.</p>', $page);
        $this->assertStringNotContainsString(self::DEFAUT, $page);

        // L'anglais, laissé vide, reprend le français.
        $this->assertStringContainsString(
            '<h2>Trois corpus, une recherche</h2>',
            $this->requete('GET', '/cedric-taldu/en/gallery')->body,
        );

        // La saisie est reproposée telle quelle à l'écran.
        $this->assertStringContainsString('value="Trois corpus, une recherche"', $this->requete('GET', self::ECRAN)->body);
    }

    public function test_le_texte_est_echappe_jamais_interprete(): void
    {
        $this->postAvecJeton(self::ECRAN, [
            'titre_fr' => '<img src=x onerror=alert(1)>',
            'texte_fr' => '<script>alert(1)</script> & <b>gras</b>',
        ]);

        $page = $this->requete('GET', '/cedric-taldu/fr/galerie')->body;
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;gras&lt;/b&gt;', $page);
        $this->assertStringContainsString('<h2>&lt;img src=x onerror=alert(1)&gt;</h2>', $page);
        $this->assertStringNotContainsString('<script>alert(1)', $page);
        $this->assertStringNotContainsString('<img src=x', $page);
    }

    public function test_vider_les_champs_rend_l_introduction_par_defaut(): void
    {
        $this->postAvecJeton(self::ECRAN, ['titre_fr' => 'Titre', 'texte_fr' => 'Texte.']);
        $this->postAvecJeton(self::ECRAN, ['titre_fr' => '', 'texte_fr' => '']);

        $this->assertStringContainsString(self::DEFAUT, $this->requete('GET', '/cedric-taldu/fr/galerie')->body);
    }

    public function test_la_modification_entre_dans_l_historique(): void
    {
        $this->postAvecJeton(self::ECRAN, ['titre_fr' => 'Titre', 'texte_fr' => 'Première version.']);
        $this->postAvecJeton(self::ECRAN, ['titre_fr' => 'Titre', 'texte_fr' => 'Seconde version.']);

        $this->assertStringContainsString(
            'Page « Galerie »',
            $this->requete('GET', '/cedric-taldu/admin/historique')->body,
        );
    }
}
