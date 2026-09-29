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

    private function bloc(string $html, string $debut, string $fin): string
    {
        $a = strpos($html, $debut);
        $this->assertNotFalse($a, 'Absent : ' . $debut);
        $b = strpos($html, $fin, $a);
        $this->assertNotFalse($b, 'Absent : ' . $fin);

        return substr($html, $a, $b - $a);
    }
}
