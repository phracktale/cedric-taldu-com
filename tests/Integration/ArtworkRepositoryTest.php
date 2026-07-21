<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Catalog\Artwork;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\ArtworkRepository;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\SeriesFactory;

final class ArtworkRepositoryTest extends DatabaseTestCase
{
    private ArtworkRepository $depot;
    private int $rubriqueId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = new ArtworkRepository($this->pdo);
        $this->rubriqueId = (new CategoryFactory($this->pdo))
            ->translated('fr', 'encres', 'Encres')
            ->create();
    }

    private function oeuvre(): ArtworkFactory
    {
        return new ArtworkFactory($this->pdo);
    }

    /**
     * @param list<Artwork> $oeuvres
     * @return list<string>
     */
    private function titres(array $oeuvres): array
    {
        return array_map(static fn (Artwork $o): string => $o->title(Locale::Fr), $oeuvres);
    }

    // ------------------------------------------------------ page rubrique

    public function test_les_œuvres_publiees_d_une_rubrique_sont_listees(): void
    {
        $this->oeuvre()->translated('fr', 'pilier-i', 'Pilier I')->create($this->rubriqueId);
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubriqueId);

        $this->assertCount(2, $this->depot->findPublishedInCategory($this->rubriqueId, null, 24, 0));
    }

    public function test_un_brouillon_n_apparait_jamais_dans_une_rubrique(): void
    {
        $this->oeuvre()->translated('fr', 'pilier-i', 'Pilier I')->create($this->rubriqueId);
        $this->oeuvre()->draft()->translated('fr', 'brouillon', 'Brouillon')->create($this->rubriqueId);

        $this->assertSame(['Pilier I'], $this->titres($this->depot->findPublishedInCategory($this->rubriqueId, null, 24, 0)));
    }

    public function test_une_œuvre_vendue_reste_visible(): void
    {
        // Le site est le portfolio de l'artiste autant que sa boutique : retirer
        // les pieces vendues viderait la galerie a mesure qu'elle marche.
        $this->oeuvre()->sold()->translated('fr', 'vendue', 'Vendue')->create($this->rubriqueId);

        $this->assertCount(1, $this->depot->findPublishedInCategory($this->rubriqueId, null, 24, 0));
    }

    public function test_l_ordre_suit_la_position_puis_l_identifiant(): void
    {
        $this->oeuvre()->atPosition(20)->translated('fr', 'seconde', 'Seconde')->create($this->rubriqueId);
        $this->oeuvre()->atPosition(10)->translated('fr', 'premiere', 'Première')->create($this->rubriqueId);

        $this->assertSame(
            ['Première', 'Seconde'],
            $this->titres($this->depot->findPublishedInCategory($this->rubriqueId, null, 24, 0))
        );
    }

    public function test_les_œuvres_d_une_autre_rubrique_ne_remontent_pas(): void
    {
        $autre = (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();
        $this->oeuvre()->translated('fr', 'encre', 'Encre')->create($this->rubriqueId);
        $this->oeuvre()->translated('fr', 'huile', 'Huile')->create($autre);

        $this->assertSame(['Encre'], $this->titres($this->depot->findPublishedInCategory($this->rubriqueId, null, 24, 0)));
    }

    // ------------------------------------------------------ filtre de serie

    public function test_le_filtre_de_serie_restreint_la_grille(): void
    {
        $serie = (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($this->rubriqueId);
        $this->oeuvre()->inSeries($serie)->translated('fr', 'pilier-i', 'Pilier I')->create($this->rubriqueId);
        $this->oeuvre()->translated('fr', 'hors-serie', 'Hors série')->create($this->rubriqueId);

        $this->assertSame(
            ['Pilier I'],
            $this->titres($this->depot->findPublishedInCategory($this->rubriqueId, $serie, 24, 0))
        );
    }

    public function test_sans_filtre_toutes_les_series_sont_melees(): void
    {
        $serie = (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($this->rubriqueId);
        $this->oeuvre()->inSeries($serie)->translated('fr', 'pilier-i', 'Pilier I')->create($this->rubriqueId);
        $this->oeuvre()->translated('fr', 'hors-serie', 'Hors série')->create($this->rubriqueId);

        $this->assertCount(2, $this->depot->findPublishedInCategory($this->rubriqueId, null, 24, 0));
    }

    // -------------------------------------------------------- pagination

    public function test_la_pagination_limite_et_decale(): void
    {
        // 02-front-public §3 : pagination a 24 œuvres.
        foreach (range(1, 5) as $i) {
            $this->oeuvre()->atPosition($i)->translated('fr', 'oeuvre-' . $i, 'Œuvre ' . $i)->create($this->rubriqueId);
        }

        $this->assertSame(['Œuvre 1', 'Œuvre 2'], $this->titres($this->depot->findPublishedInCategory($this->rubriqueId, null, 2, 0)));
        $this->assertSame(['Œuvre 3', 'Œuvre 4'], $this->titres($this->depot->findPublishedInCategory($this->rubriqueId, null, 2, 2)));
    }

    public function test_le_compte_ignore_la_pagination_mais_pas_le_filtre(): void
    {
        $serie = (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($this->rubriqueId);
        $this->oeuvre()->inSeries($serie)->translated('fr', 'a', 'A')->create($this->rubriqueId);
        $this->oeuvre()->translated('fr', 'b', 'B')->create($this->rubriqueId);
        $this->oeuvre()->draft()->translated('fr', 'c', 'C')->create($this->rubriqueId);

        $this->assertSame(2, $this->depot->countPublishedInCategory($this->rubriqueId, null));
        $this->assertSame(1, $this->depot->countPublishedInCategory($this->rubriqueId, $serie));
    }

    public function test_une_limite_ou_un_decalage_negatif_est_ramene_a_zero(): void
    {
        // La page vient de l'URL : elle est validee en amont, mais le depot ne
        // doit pas produire de SQL invalide si elle ne l'etait pas.
        $this->oeuvre()->translated('fr', 'a', 'A')->create($this->rubriqueId);

        $this->assertSame([], $this->depot->findPublishedInCategory($this->rubriqueId, null, -5, -5));
    }

    // ---------------------------------------------------------- par slug

    public function test_une_œuvre_est_retrouvee_par_son_slug(): void
    {
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubriqueId);

        $oeuvre = $this->depot->findBySlug(Locale::Fr, Slug::fromString('articulation'));

        $this->assertNotNull($oeuvre);
        $this->assertSame('Articulation', $oeuvre->title(Locale::Fr));
    }

    public function test_un_brouillon_est_introuvable_par_son_slug(): void
    {
        // 06-securite §8 : 404 et non 403, pour ne pas confirmer son existence.
        $this->oeuvre()->draft()->translated('fr', 'brouillon', 'Brouillon')->create($this->rubriqueId);

        $this->assertNull($this->depot->findBySlug(Locale::Fr, Slug::fromString('brouillon')));
    }

    public function test_le_slug_francais_sert_a_l_url_anglaise_quand_la_traduction_manque(): void
    {
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubriqueId);

        $this->assertNotNull($this->depot->findBySlug(Locale::En, Slug::fromString('articulation')));
    }

    public function test_le_slug_francais_ne_repond_pas_en_anglais_quand_l_anglais_existe(): void
    {
        $this->oeuvre()
            ->translated('fr', 'articulation', 'Articulation')
            ->translated('en', 'joint', 'Joint')
            ->create($this->rubriqueId);

        $this->assertNull($this->depot->findBySlug(Locale::En, Slug::fromString('articulation')));
        $this->assertNotNull($this->depot->findBySlug(Locale::En, Slug::fromString('joint')));
    }

    public function test_toutes_les_donnees_de_la_fiche_sont_chargees(): void
    {
        $this->oeuvre()
            ->priced(45000)
            ->madeIn(2026)
            ->usingTechnique('Encre de Chine sur papier')
            ->measuring(100, 165)
            ->signed()
            ->translated('fr', 'articulation', 'Articulation', '<p>Description.</p>', '<p>Détail.</p>')
            ->create($this->rubriqueId);

        $oeuvre = $this->depot->findBySlug(Locale::Fr, Slug::fromString('articulation'));

        $this->assertNotNull($oeuvre);
        $this->assertSame(45000, $oeuvre->price?->cents);
        $this->assertSame('Encre de Chine sur papier · 10 × 16,5 cm · 2026 · Signée', $oeuvre->specifications(Locale::Fr));
        $this->assertTrue($oeuvre->isPurchasable());
    }

    public function test_une_œuvre_sans_prix_ni_dimensions_se_charge_sans_erreur(): void
    {
        $this->oeuvre()
            ->notForSale()
            ->madeIn(null)
            ->usingTechnique(null)
            ->measuring(null, null)
            ->translated('fr', 'sans-rien', 'Sans rien')
            ->create($this->rubriqueId);

        $oeuvre = $this->depot->findBySlug(Locale::Fr, Slug::fromString('sans-rien'));

        $this->assertNotNull($oeuvre);
        $this->assertNull($oeuvre->price);
        $this->assertNull($oeuvre->dimensions);
        $this->assertFalse($oeuvre->isPurchasable());
    }

    // ------------------------------------------------------ œuvres liees

    public function test_les_œuvres_liees_privilegient_la_meme_serie(): void
    {
        // 02-front-public §4 : meme serie, puis meme rubrique, puis les plus
        // recentes ; jamais l'œuvre courante.
        $serie = (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($this->rubriqueId);

        $courante = $this->oeuvre()->inSeries($serie)->translated('fr', 'courante', 'Courante')->create($this->rubriqueId);
        $this->oeuvre()->inSeries($serie)->translated('fr', 'meme-serie', 'Même série')->create($this->rubriqueId);
        $this->oeuvre()->translated('fr', 'meme-rubrique', 'Même rubrique')->create($this->rubriqueId);

        $oeuvre = $this->depot->findById($courante);
        $this->assertNotNull($oeuvre);

        $liees = $this->depot->findRelated($oeuvre, 3);

        $this->assertSame(['Même série', 'Même rubrique'], $this->titres($liees));
    }

    public function test_l_œuvre_courante_ne_figure_jamais_parmi_ses_liees(): void
    {
        $courante = $this->oeuvre()->translated('fr', 'courante', 'Courante')->create($this->rubriqueId);
        $this->oeuvre()->translated('fr', 'autre', 'Autre')->create($this->rubriqueId);

        $oeuvre = $this->depot->findById($courante);
        $this->assertNotNull($oeuvre);

        $this->assertSame(['Autre'], $this->titres($this->depot->findRelated($oeuvre, 3)));
    }

    public function test_les_œuvres_liees_completent_avec_les_plus_recentes(): void
    {
        $autre = (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();

        $courante = $this->oeuvre()->translated('fr', 'courante', 'Courante')->create($this->rubriqueId);
        $this->oeuvre()->publishedAt('2026-06-01 00:00:00')->translated('fr', 'ancienne', 'Ancienne')->create($autre);
        $this->oeuvre()->publishedAt('2026-07-01 00:00:00')->translated('fr', 'recente', 'Récente')->create($autre);

        $oeuvre = $this->depot->findById($courante);
        $this->assertNotNull($oeuvre);

        $this->assertSame(['Récente', 'Ancienne'], $this->titres($this->depot->findRelated($oeuvre, 3)));
    }

    public function test_les_œuvres_liees_respectent_la_limite(): void
    {
        $courante = $this->oeuvre()->translated('fr', 'courante', 'Courante')->create($this->rubriqueId);
        foreach (range(1, 5) as $i) {
            $this->oeuvre()->translated('fr', 'liee-' . $i, 'Liée ' . $i)->create($this->rubriqueId);
        }

        $oeuvre = $this->depot->findById($courante);
        $this->assertNotNull($oeuvre);

        $this->assertCount(3, $this->depot->findRelated($oeuvre, 3));
    }

    public function test_un_brouillon_n_est_jamais_propose_comme_œuvre_liee(): void
    {
        $courante = $this->oeuvre()->translated('fr', 'courante', 'Courante')->create($this->rubriqueId);
        $this->oeuvre()->draft()->translated('fr', 'brouillon', 'Brouillon')->create($this->rubriqueId);

        $oeuvre = $this->depot->findById($courante);
        $this->assertNotNull($oeuvre);

        $this->assertSame([], $this->depot->findRelated($oeuvre, 3));
    }

    // ---------------------------------------------------- vitrine d accueil

    public function test_les_œuvres_de_la_vitrine_sont_rendues_dans_l_ordre_demande(): void
    {
        // Module 2 de l'accueil : trois œuvres choisies par l'artiste, dont
        // celle du milieu est plus haute. L'ordre est donc signifiant.
        $a = $this->oeuvre()->translated('fr', 'a', 'A')->create($this->rubriqueId);
        $b = $this->oeuvre()->translated('fr', 'b', 'B')->create($this->rubriqueId);
        $c = $this->oeuvre()->translated('fr', 'c', 'C')->create($this->rubriqueId);

        $this->assertSame(['C', 'A', 'B'], $this->titres($this->depot->findByIds([$c, $a, $b])));
    }

    public function test_la_vitrine_ignore_un_identifiant_inconnu_ou_non_publie(): void
    {
        $a = $this->oeuvre()->translated('fr', 'a', 'A')->create($this->rubriqueId);
        $brouillon = $this->oeuvre()->draft()->translated('fr', 'brouillon', 'Brouillon')->create($this->rubriqueId);

        $this->assertSame(['A'], $this->titres($this->depot->findByIds([$a, $brouillon, 999_999])));
    }

    public function test_une_vitrine_vide_ne_declenche_aucune_requete_invalide(): void
    {
        // Une clause IN () vide est une erreur de syntaxe SQL : le cas doit etre
        // traite avant la requete, pas par la base.
        $this->assertSame([], $this->depot->findByIds([]));
    }
}
