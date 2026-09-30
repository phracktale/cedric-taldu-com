<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Vignettes d'œuvres nettes (décision du 2026-09-30) : chaque vignette choisit
 * par point de rupture un fichier à sa largeur d'affichage exacte, en 1x/2x,
 * sans `sizes` ; la grille a des colonnes de largeur fixe, engendrées depuis
 * les mêmes tables (ThumbnailLayout).
 */
final class VignettesNettesTest extends FunctionalTestCase
{
    public function test_la_vignette_d_une_galerie_sert_sa_largeur_exacte(): void
    {
        $galerie = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $media = (new MediaFactory($this->pdo))->named('pilier')->sized(3000, 4000)->create();
        (new ArtworkFactory($this->pdo))->published()->withPrimaryMedia($media)
            ->translated('fr', 'pilier', 'Pilier')->create($galerie);

        $corps = $this->get('/cedric-taldu/fr/galerie/encres')->body;

        $this->assertStringContainsString(
            '<source media="(min-width: 64rem)" type="image/webp" srcset="/cedric-taldu/media/pilier-245.webp 1x, /cedric-taldu/media/pilier-490.webp 2x">',
            $corps,
        );
        $this->assertDoesNotMatchRegularExpression('#<a class="oeuvre[^"]*".*?sizes=.*?</a>#s', $corps);
        $this->assertStringContainsString('.oeuvres { grid-template-columns: repeat(1, 18rem)', $corps);
    }

    public function test_le_facteur_de_zoom_d_apparence_est_integre_aux_largeurs(): void
    {
        $this->pdo->exec("INSERT INTO settings (`key`, value, updated_at) VALUES ('theme.images', '{\"zoom\":80}', NOW())");
        $galerie = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $media = (new MediaFactory($this->pdo))->named('pilier')->sized(3000, 4000)->create();
        (new ArtworkFactory($this->pdo))->published()->withPrimaryMedia($media)
            ->translated('fr', 'pilier', 'Pilier')->create($galerie);

        $this->assertStringContainsString(
            'srcset="/cedric-taldu/media/pilier-196.webp 1x, /cedric-taldu/media/pilier-392.webp 2x"',
            $this->get('/cedric-taldu/fr/galerie/encres')->body,
        );
    }
}
