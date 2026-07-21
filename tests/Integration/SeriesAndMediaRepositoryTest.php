<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Catalog\Series;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\MediaRepository;
use App\Repository\SeriesRepository;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\SeriesFactory;

final class SeriesAndMediaRepositoryTest extends DatabaseTestCase
{
    private SeriesRepository $series;
    private MediaRepository $medias;
    private int $rubriqueId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->series = new SeriesRepository($this->pdo);
        $this->medias = new MediaRepository($this->pdo);
        $this->rubriqueId = (new CategoryFactory($this->pdo))
            ->translated('fr', 'encres', 'Encres')
            ->create();
    }

    private function serie(): SeriesFactory
    {
        return new SeriesFactory($this->pdo);
    }

    // ------------------------------------------------------------- series

    public function test_les_series_publiees_d_une_rubrique_sont_listees(): void
    {
        // 02-front-public §3 : « Toutes » plus une puce par serie publiee.
        $this->serie()->translated('fr', 'piliers', 'Piliers')->create($this->rubriqueId);
        $this->serie()->translated('fr', 'fondations', 'Fondations')->create($this->rubriqueId);

        $this->assertCount(2, $this->series->findPublishedInCategory($this->rubriqueId));
    }

    public function test_une_serie_non_publiee_n_apparait_pas_dans_les_filtres(): void
    {
        $this->serie()->translated('fr', 'piliers', 'Piliers')->create($this->rubriqueId);
        $this->serie()->published(false)->translated('fr', 'cachee', 'Cachée')->create($this->rubriqueId);

        $this->assertCount(1, $this->series->findPublishedInCategory($this->rubriqueId));
    }

    public function test_les_series_d_une_autre_rubrique_ne_remontent_pas(): void
    {
        $autre = (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();
        $this->serie()->translated('fr', 'piliers', 'Piliers')->create($this->rubriqueId);
        $this->serie()->translated('fr', 'figures', 'Figures')->create($autre);

        $series = $this->series->findPublishedInCategory($this->rubriqueId);

        $this->assertSame(['Piliers'], array_map(static fn (Series $s): string => $s->title(Locale::Fr), $series));
    }

    public function test_l_ordre_des_filtres_suit_la_position(): void
    {
        $this->serie()->atPosition(20)->translated('fr', 'seconde', 'Seconde')->create($this->rubriqueId);
        $this->serie()->atPosition(10)->translated('fr', 'premiere', 'Première')->create($this->rubriqueId);

        $series = $this->series->findPublishedInCategory($this->rubriqueId);

        $this->assertSame('Première', $series[0]->title(Locale::Fr));
    }

    public function test_une_serie_est_retrouvee_par_son_slug_dans_sa_rubrique(): void
    {
        // Le filtre arrive par « ?serie=piliers » : il doit etre resolu DANS la
        // rubrique consultee, sans quoi un slug d'une autre rubrique filtrerait
        // la grille sur rien.
        $autre = (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();
        $this->serie()->translated('fr', 'piliers', 'Piliers')->create($this->rubriqueId);
        $this->serie()->translated('fr', 'figures', 'Figures')->create($autre);

        $this->assertNotNull($this->series->findBySlugInCategory($this->rubriqueId, Locale::Fr, Slug::fromString('piliers')));
        $this->assertNull($this->series->findBySlugInCategory($this->rubriqueId, Locale::Fr, Slug::fromString('figures')));
    }

    public function test_le_slug_anglais_d_une_serie_est_resolu(): void
    {
        $this->serie()
            ->translated('fr', 'piliers', 'Piliers')
            ->translated('en', 'pillars', 'Pillars')
            ->create($this->rubriqueId);

        $serie = $this->series->findBySlugInCategory($this->rubriqueId, Locale::En, Slug::fromString('pillars'));

        $this->assertNotNull($serie);
        $this->assertSame('Pillars', $serie->title(Locale::En));
    }

    public function test_une_serie_non_publiee_est_introuvable_par_son_slug(): void
    {
        $this->serie()->published(false)->translated('fr', 'cachee', 'Cachée')->create($this->rubriqueId);

        $this->assertNull($this->series->findBySlugInCategory($this->rubriqueId, Locale::Fr, Slug::fromString('cachee')));
    }

    // ------------------------------------------------------------- medias

    public function test_les_medias_sont_charges_par_lot_et_indexes_par_identifiant(): void
    {
        // Une requete par image sur une grille de vingt-quatre œuvres serait
        // vingt-quatre allers-retours : les medias se chargent en un seul lot.
        $a = (new MediaFactory($this->pdo))->named('a')->translated('fr', 'Alpha')->create();
        $b = (new MediaFactory($this->pdo))->named('b')->translated('fr', 'Bravo')->create();

        $medias = $this->medias->findByIds([$a, $b]);

        $this->assertArrayHasKey($a, $medias);
        $this->assertArrayHasKey($b, $medias);
        $this->assertSame('Alpha', $medias[$a]->alt(Locale::Fr));
    }

    public function test_un_lot_vide_ne_declenche_aucune_requete_invalide(): void
    {
        $this->assertSame([], $this->medias->findByIds([]));
    }

    public function test_un_identifiant_inconnu_est_simplement_absent(): void
    {
        $a = (new MediaFactory($this->pdo))->named('a')->create();

        $medias = $this->medias->findByIds([$a, 999_999]);

        $this->assertCount(1, $medias);
    }

    public function test_les_dimensions_et_le_point_focal_sont_relus(): void
    {
        $id = (new MediaFactory($this->pdo))
            ->named('focal')
            ->sized(1600, 900)
            ->withFocalPoint(30, 20)
            ->translated('fr', 'Portrait')
            ->create();

        $media = $this->medias->findByIds([$id])[$id];

        $this->assertSame('1600 / 900', $media->aspectRatio());
        $this->assertSame('30% 20%', $media->objectPosition());
        $this->assertSame([320, 640, 1024, 1600], $media->availableWidths());
    }

    public function test_le_texte_alternatif_anglais_est_relu_quand_il_existe(): void
    {
        $id = (new MediaFactory($this->pdo))
            ->named('bilingue')
            ->translated('fr', 'Encre de Chine')
            ->translated('en', 'India ink')
            ->create();

        $media = $this->medias->findByIds([$id])[$id];

        $this->assertSame('Encre de Chine', $media->alt(Locale::Fr));
        $this->assertSame('India ink', $media->alt(Locale::En));
    }
}
