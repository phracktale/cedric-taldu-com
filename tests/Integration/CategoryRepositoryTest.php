<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\CategoryRepository;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;

final class CategoryRepositoryTest extends DatabaseTestCase
{
    private CategoryRepository $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = new CategoryRepository($this->pdo);
    }

    private function factory(): CategoryFactory
    {
        return new CategoryFactory($this->pdo);
    }

    // ------------------------------------------------------------ listing

    public function test_les_rubriques_publiees_sont_listees(): void
    {
        $this->factory()->translated('fr', 'encres', 'Encres')->create();
        $this->factory()->translated('fr', 'peintures', 'Peintures')->create();

        $rubriques = $this->depot->findPublished();

        $this->assertCount(2, $rubriques);
    }

    public function test_une_rubrique_non_publiee_n_apparait_pas(): void
    {
        // C'est ce qui rend le menu Galerie et le module 4 de l'accueil sûrs :
        // une rubrique en préparation ne fuite pas dans la navigation.
        $this->factory()->translated('fr', 'encres', 'Encres')->create();
        $this->factory()->published(false)->translated('fr', 'brouillon', 'Brouillon')->create();

        $this->assertCount(1, $this->depot->findPublished());
    }

    public function test_l_ordre_suit_la_position_choisie_par_l_artiste(): void
    {
        $this->factory()->atPosition(20)->translated('fr', 'peintures', 'Peintures')->create();
        $this->factory()->atPosition(10)->translated('fr', 'encres', 'Encres')->create();

        $rubriques = $this->depot->findPublished();

        $this->assertSame('Encres', $rubriques[0]->title(Locale::Fr));
        $this->assertSame('Peintures', $rubriques[1]->title(Locale::Fr));
    }

    public function test_les_traductions_sont_chargees_avec_la_rubrique(): void
    {
        // Une requete par rubrique pour lire son titre traduit serait un N+1
        // sur toutes les pages du site, le menu compris.
        $this->factory()
            ->translated('fr', 'encres', 'Encres')
            ->translated('en', 'inks', 'Inks')
            ->create();

        $rubriques = $this->depot->findPublished();

        $this->assertSame('Encres', $rubriques[0]->title(Locale::Fr));
        $this->assertSame('Inks', $rubriques[0]->title(Locale::En));
    }

    public function test_la_couverture_est_rendue_quand_elle_existe(): void
    {
        $mediaId = (new MediaFactory($this->pdo))->create();
        $this->factory()->withCover($mediaId)->translated('fr', 'encres', 'Encres')->create();

        $rubriques = $this->depot->findPublished();

        $this->assertTrue($rubriques[0]->hasCover());
        $this->assertSame($mediaId, $rubriques[0]->coverMediaId);
    }

    // ---------------------------------------------------------- par slug

    public function test_une_rubrique_est_retrouvee_par_son_slug(): void
    {
        $this->factory()->translated('fr', 'encres', 'Encres')->create();

        $rubrique = $this->depot->findBySlug(Locale::Fr, Slug::fromString('encres'));

        $this->assertNotNull($rubrique);
        $this->assertSame('Encres', $rubrique->title(Locale::Fr));
    }

    public function test_le_slug_anglais_mene_a_la_rubrique_anglaise(): void
    {
        $this->factory()
            ->translated('fr', 'encres', 'Encres')
            ->translated('en', 'inks', 'Inks')
            ->create();

        $this->assertNotNull($this->depot->findBySlug(Locale::En, Slug::fromString('inks')));
    }

    public function test_le_slug_francais_sert_a_l_url_anglaise_quand_la_traduction_manque(): void
    {
        // 05-i18n-seo §3 : « Slug EN absent -> le slug FR est utilisé ».
        $this->factory()->translated('fr', 'encres', 'Encres')->create();

        $rubrique = $this->depot->findBySlug(Locale::En, Slug::fromString('encres'));

        $this->assertNotNull($rubrique);
        $this->assertSame('Encres', $rubrique->title(Locale::En));
    }

    public function test_le_slug_francais_ne_repond_pas_en_anglais_quand_l_anglais_existe(): void
    {
        // Sinon la meme rubrique serait accessible a deux URL anglaises, dont
        // une non canonique : du contenu duplique pour les moteurs.
        $this->factory()
            ->translated('fr', 'encres', 'Encres')
            ->translated('en', 'inks', 'Inks')
            ->create();

        $this->assertNull($this->depot->findBySlug(Locale::En, Slug::fromString('encres')));
    }

    public function test_un_slug_inconnu_ne_rend_rien(): void
    {
        $this->assertNull($this->depot->findBySlug(Locale::Fr, Slug::fromString('inexistante')));
    }

    public function test_une_rubrique_non_publiee_est_introuvable_par_son_slug(): void
    {
        // 06-securite §8 : pas d'enumeration. Le controleur repondra 404, et non
        // 403, pour ne pas confirmer l'existence de la rubrique.
        $this->factory()->published(false)->translated('fr', 'secrete', 'Secrète')->create();

        $this->assertNull($this->depot->findBySlug(Locale::Fr, Slug::fromString('secrete')));
    }

    // ----------------------------------------------------------- par id

    public function test_une_rubrique_est_retrouvee_par_son_identifiant(): void
    {
        $id = $this->factory()->translated('fr', 'encres', 'Encres')->create();

        $rubrique = $this->depot->findById($id);

        $this->assertNotNull($rubrique);
        $this->assertSame($id, $rubrique->id);
    }

    public function test_un_identifiant_inconnu_ne_rend_rien(): void
    {
        $this->assertNull($this->depot->findById(999_999));
    }

    // -------------------------------------------------------- robustesse

    public function test_une_charge_sql_dans_un_slug_ne_fait_rien(): void
    {
        // Le slug est deja contraint par le domaine, mais le depot doit lier ses
        // parametres quoi qu'il recoive : la defense ne repose pas sur l'appelant.
        $this->factory()->translated('fr', 'encres', 'Encres')->create();

        $resultat = $this->depot->findBySlug(Locale::Fr, Slug::fromString('encres'));

        $this->assertNotNull($resultat);
        $this->assertCount(1, $this->depot->findPublished());
    }
}
