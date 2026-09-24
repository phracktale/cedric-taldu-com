<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contrat de la feuille de style publique (revue du 2026-09-24).
 *
 * Chaque règle correspond à un défaut constaté en recette. Le CSS n'a pas de
 * moteur de rendu en test : on vérifie les déclarations elles-mêmes, pour
 * qu'une régression redevienne visible avant d'arriver en ligne.
 */
final class FeuilleDeStyleTest extends TestCase
{
    private static string $css;

    public static function setUpBeforeClass(): void
    {
        $css = file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/site.css');
        self::assertIsString($css);
        // Commentaires retirés : ils citent parfois des sélecteurs.
        self::$css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    public function test_seul_l_en_tete_du_site_est_collant(): void
    {
        // Un sélecteur « header » nu rendait collants les en-têtes de page et
        // d'article (H1 du livret figé en haut de l'écran, gênant en mobile).
        $this->assertDoesNotMatchRegularExpression('~(^|[\s,}])header\s*\{~', self::$css);
        $this->assertStringContainsString('position: sticky', $this->regle('.site-tete'));
    }

    public function test_la_page_editoriale_est_elargie(): void
    {
        $this->assertStringNotContainsString('46rem', $this->regle('.page-editoriale'));
        $this->assertMatchesRegularExpression('~max-width:\s*var\(--contenu\)~', $this->regle('.page-editoriale'));
        $this->assertMatchesRegularExpression('~--contenu:\s*7[0-5]rem~', self::$css);
    }

    public function test_le_sous_menu_passe_au_dessus_du_contenu(): void
    {
        $this->assertMatchesRegularExpression('~z-index:\s*\d+~', $this->regle('.sous-menu > ul'));
    }

    public function test_le_bouton_galerie_a_la_meme_graisse_que_les_liens(): void
    {
        // Un <button> n'hérite pas de la graisse : « Galerie » paraissait en gras.
        $this->assertStringContainsString('font-weight: inherit', $this->regle('nav a, .nav-bouton'));
    }

    public function test_le_recapitulatif_de_commande_ne_creuse_pas_la_colonne_de_gauche(): void
    {
        // « 1 / -1 » sans rangées explicites ne couvre que la première : le
        // récapitulatif étirait la rangée des coordonnées, d'où un grand vide.
        // Les étapes forment désormais une seule colonne, le bilan l'autre.
        $this->assertStringNotContainsString('grid-row: 1 / -1', self::$css);
        $this->assertStringContainsString('grid-column: 1', $this->regle('.commande-etapes'));
        $this->assertStringContainsString('grid-column: 2', $this->regle('.commande-bilan'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function gabarits(): iterable
    {
        yield 'vertical' => ['portrait', '3 / 4'];
        yield 'horizontal' => ['paysage', '4 / 3'];
        yield 'carré' => ['carre', '1 / 1'];
    }

    #[DataProvider('gabarits')]
    public function test_chaque_orientation_a_son_gabarit_fixe(string $orientation, string $ratio): void
    {
        $this->assertStringContainsString('aspect-ratio: ' . $ratio, $this->regle('.dessin--' . $orientation));
    }

    public function test_le_visuel_tient_dans_son_gabarit_avec_un_facteur_de_zoom(): void
    {
        // Jamais rogné : contain, et un zoom réglable en back-office.
        $this->assertStringContainsString('object-fit: contain', $this->regle('.dessin img'));
        $this->assertMatchesRegularExpression('~scale:\s*var\(--vignette-zoom,\s*1\)~', $this->regle('.dessin img'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stylesActifs(): iterable
    {
        foreach (['gras', 'souligne', 'inverse', 'couleur'] as $style) {
            yield $style => [$style];
        }
    }

    #[DataProvider('stylesActifs')]
    public function test_chaque_style_d_entree_active_est_decline(string $style): void
    {
        $this->assertStringContainsString('[data-actif="' . $style . '"]', self::$css);
    }

    /**
     * Corps de la N-ième règle dont le sélecteur est exactement celui donné.
     */
    private function regle(string $selecteur, int $rang = 0): string
    {
        $motif = '~(?:^|[}\s])' . preg_quote($selecteur, '~') . '\s*\{([^}]*)\}~';
        preg_match_all($motif, self::$css, $trouvees);
        $this->assertArrayHasKey($rang, $trouvees[1], 'Règle absente : ' . $selecteur);

        return $trouvees[1][$rang];
    }
}
