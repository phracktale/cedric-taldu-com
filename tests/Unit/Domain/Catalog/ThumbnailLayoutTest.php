<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\ThumbnailLayout;
use PHPUnit\Framework\TestCase;

/**
 * Vignettes d'œuvres nettes (décision du 2026-09-30) : la méthode de la fiche
 * œuvre, étendue aux grilles. Chaque carte a une largeur FIXE par point de
 * rupture ; l'œuvre tient dans son cadre (padding, bordure, gabarit par
 * orientation), multipliée par le facteur de zoom d'Apparence. Le fichier est
 * produit à cette largeur exacte, en 1x et 2x : le navigateur ne redimensionne
 * rien, les points restent nets.
 */
final class ThumbnailLayoutTest extends TestCase
{
    public function test_une_oeuvre_verticale_remplit_son_cadre_vertical(): void
    {
        // Grille, écran ≥ 64rem : carte de 18rem = 288 px ; padding 7 % de
        // chaque côté et bordure de 1 px : 288 × 0,86 − 2 = 245,68 px de large.
        // Cadre 3:4, œuvre 3:4 : elle occupe toute la largeur.
        $this->assertSame(245, ThumbnailLayout::displayWidth(3000, 4000, 'grille', 18.0, 100));
    }

    public function test_une_oeuvre_plus_etroite_que_son_cadre_est_bornee_par_la_hauteur(): void
    {
        // Œuvre 1:2 dans un cadre 3:4 (vertical) : hauteur du cadre 327,57 px,
        // largeur de l'œuvre = la moitié.
        $this->assertSame(163, ThumbnailLayout::displayWidth(2000, 4000, 'grille', 18.0, 100));
    }

    public function test_le_facteur_de_zoom_reduit_l_oeuvre_dans_son_cadre(): void
    {
        $this->assertSame(196, ThumbnailLayout::displayWidth(3000, 4000, 'grille', 18.0, 80));
    }

    public function test_une_petite_image_n_est_jamais_agrandie(): void
    {
        $this->assertSame(120, ThumbnailLayout::displayWidth(120, 160, 'grille', 18.0, 100));
    }

    public function test_la_vitrine_centrale_a_son_gabarit_allonge(): void
    {
        // `.oeuvre.large .dessin` : 3 / 4,4, quelle que soit l'orientation.
        // Carte de 16rem : 256 × 0,86 − 2 = 218,16 px ; hauteur 319,97 px.
        // Œuvre carrée : bornée par la largeur.
        $this->assertSame(218, ThumbnailLayout::displayWidth(3000, 3000, 'vitrine-large', 16.0, 100));
    }

    public function test_les_oeuvres_liees_ont_un_cadre_plus_large(): void
    {
        // `.liee .cadre` : padding de 8 %. Carte de 18rem : 288 × 0,84 − 2.
        $this->assertSame(239, ThumbnailLayout::displayWidth(3000, 4000, 'liees', 18.0, 100));
    }

    public function test_les_sources_vont_du_plus_large_point_de_rupture_au_defaut(): void
    {
        $sources = ThumbnailLayout::sources(3000, 4000, 'grille', 100);

        $this->assertSame('(min-width: 82rem)', $sources[0]['media']);
        $this->assertNull($sources[array_key_last($sources)]['media']);
        // ≥ 64rem : 245 px en 1x, 490 px en 2x.
        $source64 = array_values(array_filter($sources, static fn (array $s): bool => $s['media'] === '(min-width: 64rem)'))[0];
        $this->assertSame([[245, '1x'], [490, '2x']], $source64['candidates']);
    }

    public function test_les_largeurs_a_produire_couvrent_toutes_les_sources(): void
    {
        $largeurs = ThumbnailLayout::pixelWidths(3000, 4000, 100);

        foreach (ThumbnailLayout::CONTEXTS as $contexte) {
            foreach (ThumbnailLayout::sources(3000, 4000, $contexte, 100) as $source) {
                foreach ($source['candidates'] as [$largeur]) {
                    $this->assertContains($largeur, $largeurs, $contexte . ' ' . $largeur);
                }
            }
        }
        $this->assertSame($largeurs, array_values(array_unique($largeurs)));
    }

    public function test_une_image_etroite_a_des_densites_intermediaires(): void
    {
        // 400 px de large : le 2x de 245 serait 490, au-delà de l'original.
        // Troisième source : écran ≥ 64rem (82, 80, puis 64).
        $source = ThumbnailLayout::sources(400, 533, 'grille', 100)[2];
        $this->assertSame([[245, '1x'], [400, '1.63x']], $source['candidates']);
    }

    public function test_les_cartes_tiennent_dans_la_largeur_de_contenu(): void
    {
        // Contenu : 90 % de l'écran (padding 5vw), 1180 px au plus. Les cartes
        // d'une rangée et leurs gouttières doivent y tenir, à chaque palier.
        foreach (ThumbnailLayout::ROWS as $groupe => $dispositions) {
            // Tous les paliers où quelque chose change : disposition ou largeur.
            $paliers = array_column($dispositions, 0);
            foreach ($dispositions as [, $rangees]) {
                foreach (array_merge(...$rangees) as $contexte) {
                    $paliers = [...$paliers, ...array_column(ThumbnailLayout::WIDTHS[$contexte], 0)];
                }
            }

            foreach (array_unique($paliers) as $min) {
                [, $rangees, $gouttiere] = ThumbnailLayout::rowsAt($groupe, (float) $min);
                $contenu = min(max((float) $min, 20.0) * 0.9, 1180 / 16);

                foreach ($rangees as $rangee) {
                    $cartes = array_sum(array_map(
                        static fn (string $c): float => ThumbnailLayout::cardWidth($c, (float) $min),
                        $rangee,
                    ));
                    $this->assertLessThanOrEqual(
                        $contenu,
                        $cartes + $gouttiere * (count($rangee) - 1),
                        $groupe . ' à ' . $min . 'rem',
                    );
                }
            }
        }
    }

    public function test_la_feuille_de_style_des_grilles_vient_des_memes_largeurs(): void
    {
        // Une seule source de vérité : la CSS des grilles est engendrée depuis
        // ces tables, les fichiers aussi. Elles ne peuvent pas diverger.
        $css = ThumbnailLayout::css();

        $this->assertStringContainsString('.oeuvres { grid-template-columns: repeat(1, 18rem); justify-content: center; }', $css);
        $this->assertStringContainsString('@media (min-width: 64rem) { .oeuvres { grid-template-columns: repeat(3, 18rem); } }', $css);
        $this->assertStringContainsString('@media (min-width: 47.5rem) { .liees-grid { grid-template-columns: repeat(3, 13rem); } }', $css);
        $this->assertStringContainsString('@media (min-width: 64rem) { .vitrine-grid { grid-template-columns: 19rem 16rem 19rem; }', $css);
        $this->assertStringNotContainsString('<', $css);
    }
}
