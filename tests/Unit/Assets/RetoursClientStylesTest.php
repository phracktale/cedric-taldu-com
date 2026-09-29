<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Retours client du 2026-09-29 portant sur la mise en page : chaque correction
 * est une règle de site.css, fixée ici pour qu'une retouche ultérieure ne la
 * défasse pas sans le voir.
 */
final class RetoursClientStylesTest extends TestCase
{
    private static function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/site.css');
    }

    /**
     * @return list<string>
     */
    private static function regles(string $selecteur): array
    {
        preg_match_all('/(?:^|})\s*' . preg_quote($selecteur, '/') . '\s*\{([^}]*)\}/m', self::css(), $m);

        return $m[1];
    }

    public function test_la_langue_courante_a_la_typographie_du_menu(): void
    {
        $this->assertStringContainsString('text-transform: uppercase', implode('', self::regles('.langue-courante')));
    }

    public function test_l_entree_active_inversee_ne_decale_pas_l_onglet(): void
    {
        // La marge intérieure de l'inversé s'applique à TOUTES les entrées :
        // l'onglet actif ne monte plus au-dessus des autres.
        $inverse = implode('', self::regles('nav[data-actif="inverse"] a,' . "\n" . 'nav[data-actif="inverse"] .nav-bouton'));
        $this->assertStringContainsString('padding: .2rem .5rem', $inverse);
    }

    public function test_une_image_qui_ne_remplit_pas_sa_vignette_a_un_fond_blanc(): void
    {
        $this->assertStringContainsString('background: #fff', implode('', self::regles('.dessin:has(picture)')));
    }

    public function test_la_vitrine_ne_touche_plus_le_hero(): void
    {
        $this->assertStringContainsString('padding-top: 3.125rem', implode('', self::regles('.vitrine')));
    }

    public function test_la_liste_des_actus_a_une_colonne_de_texte_large(): void
    {
        $lien = implode('', self::regles('.actus-liste .actu-lien'));
        $this->assertStringContainsString('display: grid', $lien);
        $this->assertStringContainsString('minmax(0, 1fr)', $lien);
        // La grille compacte de l'accueil ne s'applique pas à la liste.
        $this->assertStringContainsString('display: block', implode('', self::regles('.actus-liste .actu')));
    }

    public function test_les_paragraphes_du_contact_ne_sont_plus_restreints(): void
    {
        $this->assertStringNotContainsString('.contact p { max-width: 50ch', self::css());
    }

    public function test_les_liens_du_pied_de_page_sont_soulignes_en_minuscules_sans_double_trait(): void
    {
        $pied = implode('', self::regles('.foot-legal a'));
        $this->assertStringContainsString('text-transform: none', $pied);
        $this->assertStringContainsString('text-decoration: underline', $pied);
        $this->assertStringContainsString('border-bottom: 0', $pied);
    }
}
