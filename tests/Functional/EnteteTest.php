<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;

/**
 * En-tête sur deux lignes (retours du 2026-09-25) : logo, compte, panier et
 * langues sur la première ; menu de navigation sur la seconde.
 */
final class EnteteTest extends FunctionalTestCase
{
    public function test_la_premiere_ligne_porte_logo_panier_et_langues(): void
    {
        $haut = $this->bloc($this->get('/cedric-taldu/fr/a-propos')->body, '<div class="nav nav-haut">', '</div><!-- /nav-haut -->');

        $this->assertStringContainsString('class="brand"', $haut);
        $this->assertStringContainsString('class="panier-lien"', $haut);
        $this->assertStringContainsString('class="langues"', $haut);
        $this->assertStringNotContainsString('id="menu"', $haut);
    }

    public function test_la_langue_courante_a_le_meme_style_que_les_autres(): void
    {
        // Retour client du 2026-09-29 : « Français » (langue courante, simple
        // texte) n'avait pas la typographie de « English » (lien du menu).
        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('<span class="langue-courante" aria-current="true">Français</span>', $corps);
    }

    public function test_le_menu_est_sur_une_seconde_ligne(): void
    {
        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertMatchesRegularExpression('~</div><!-- /nav-haut -->\s*<nav aria-label="[^"]+" class="nav-bas"[^>]*>\s*<ul id="menu">~', $corps);
    }

    // ---- menu mobile (demande du 2026-10-13) ----

    public function test_le_burger_est_le_dernier_element_de_la_barre_et_ouvre_le_menu(): void
    {
        // À droite de la barre : le panier, puis le burger. Le bouton porte un
        // nom accessible ; ses trois barres sont décoratives.
        $haut = $this->bloc($this->get('/cedric-taldu/fr/a-propos')->body, '<div class="nav nav-haut">', '</div><!-- /nav-haut -->');

        $this->assertMatchesRegularExpression(
            '~<button type="button" class="burger" aria-expanded="false" aria-controls="menu" aria-label="Menu">~',
            $haut,
        );
        $this->assertGreaterThan(strpos($haut, 'class="panier-lien"'), strpos($haut, 'class="burger"'));
    }

    public function test_le_menu_mobile_reprend_le_compte_et_les_langues_apres_la_navigation(): void
    {
        // Sur petit écran la barre ne garde que logo, panier et burger : le
        // compte et les langues se retrouvent dans le menu, APRÈS les pages.
        $menu = $this->bloc($this->get('/cedric-taldu/fr/a-propos')->body, '<ul id="menu">', '</nav>');

        $compte = strpos($menu, 'class="menu-mobile menu-mobile-compte"');
        $langues = strpos($menu, 'class="menu-mobile menu-mobile-langues"');

        $this->assertNotFalse($compte);
        $this->assertNotFalse($langues);
        $this->assertGreaterThan(strrpos(substr($menu, 0, $compte), '</li>'), $compte);
        $this->assertGreaterThan($compte, $langues);
        $this->assertStringContainsString('href="/cedric-taldu/fr/compte"', substr($menu, $compte));
        $this->assertStringContainsString('hreflang="en"', substr($menu, $langues));
    }

    public function test_les_coordonnees_ferment_le_menu_mobile(): void
    {
        $this->pdo->prepare("INSERT INTO settings (`key`, value, updated_at) VALUES ('contact.page', :v, NOW())")
            ->execute(['v' => json_encode([
                'common' => ['address' => "25 allée des Lilas\n80470 Dreuil-lès-Amiens", 'phone' => '06 12 34 56 78', 'email' => 'contact@example.test'],
            ], JSON_THROW_ON_ERROR)]);

        $menu = $this->bloc($this->get('/cedric-taldu/fr/a-propos')->body, '<ul id="menu">', '</nav>');
        $coordonnees = strpos($menu, 'class="menu-mobile menu-mobile-coordonnees"');

        $this->assertNotFalse($coordonnees);
        $this->assertGreaterThan(strpos($menu, 'menu-mobile-langues'), $coordonnees);
        $this->assertStringContainsString('25 allée des Lilas', $menu);
        $this->assertStringContainsString('href="tel:+33612345678"', $menu);
        $this->assertStringContainsString('href="mailto:contact@example.test"', $menu);
    }

    public function test_sans_coordonnees_le_menu_mobile_n_a_pas_de_bloc_vide(): void
    {
        $this->assertStringNotContainsString(
            'menu-mobile-coordonnees',
            $this->get('/cedric-taldu/fr/a-propos')->body,
        );
    }

    private function bloc(string $html, string $debut, string $fin): string
    {
        $a = strpos($html, $debut);
        $this->assertNotFalse($a, 'Absent : ' . $debut);
        $b = strpos($html, $fin, $a);
        $this->assertNotFalse($b, 'Absent : ' . $fin);

        return substr($html, $a, $b - $a);
    }
}
