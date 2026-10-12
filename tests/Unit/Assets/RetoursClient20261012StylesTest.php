<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Compte rendu client du 2026-10-12, points de mise en page : chaque correction
 * est une règle de site.css, fixée ici pour qu'une retouche ne la défasse pas
 * sans le voir.
 */
final class RetoursClient20261012StylesTest extends TestCase
{
    private static function css(): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/site.css');

        // Commentaires retirés : ils citent parfois des sélecteurs.
        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    private static function regle(string $selecteur): string
    {
        preg_match_all('/(?:^|})\s*' . preg_quote($selecteur, '/') . '\s*\{([^}]*)\}/m', self::css(), $m);

        return implode('', $m[1]);
    }

    // ------------------------------------------------- 1. textes trop étroits

    public function test_la_largeur_de_texte_demandee_est_un_reglage_commun(): void
    {
        // 1050 px, la largeur demandée le 2026-09-29 pour les pages — seule la
        // page éditoriale l'avait reçue.
        $this->assertMatchesRegularExpression('~--mesure:\s*65\.625rem~', self::css());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blocsDeTexte(): iterable
    {
        yield 'introduction de galerie et de page' => ['.page-head .intro'];
        yield 'bande « méthode » d’une galerie' => ['.methode p'];
        yield 'introduction du triptyque' => ['.triptyque .intro'];
        yield 'bandes contrastées' => ['.boutique p, .passerelle p'];
        yield 'section contact de l’accueil' => ['section.contact p'];
        yield 'description d’une exposition' => ['.article-expo-description'];
    }

    #[DataProvider('blocsDeTexte')]
    public function test_les_blocs_de_texte_ne_sont_plus_serres(string $selecteur): void
    {
        $regle = self::regle($selecteur);

        $this->assertStringContainsString('max-width: var(--mesure)', $regle);
        // Les anciennes mesures en caractères (50 à 58ch, ~520 px) ont disparu.
        $this->assertDoesNotMatchRegularExpression('~max-width:\s*\d+ch~', $regle);
    }

    // --------------------------------------------- 3. bouton de l'accueil

    public function test_le_bouton_du_triptyque_ne_colle_plus_au_filet(): void
    {
        // Le bouton suivait la grille, bordée d'un filet, sans aucun espace.
        $this->assertMatchesRegularExpression('~margin-top:\s*[1-9]~', self::regle('.triptyque .cta-row'));
    }

    // ------------------------------------------------------- 5. page Actus

    public function test_la_page_des_actus_n_a_pas_de_fond(): void
    {
        $regle = self::regle('.actus-page');

        $this->assertNotSame('', $regle, 'Règle absente : .actus-page');
        $this->assertStringNotContainsString('background', $regle);
    }

    public function test_chaque_actu_est_encadree_d_un_filet_gris_a_30_pour_cent(): void
    {
        $this->assertStringContainsString(
            'border: 1px solid rgba(128, 128, 128, .3)',
            self::regle('.actus-liste .actu'),
        );
        // Un cadre par actu : plus de filets horizontaux entre elles.
        $this->assertStringNotContainsString('border-top', self::regle('.actus-liste'));
        $this->assertStringNotContainsString('border-bottom', self::regle('.actus-liste .actu'));
    }
}
