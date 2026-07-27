<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Core\Container;
use App\Core\ClockInterface;
use App\Core\Csrf;
use App\Core\RandomInterface;
use App\Repository\Admin\MediaAdminRepository;
use App\Service\Media\ImageProcessor;
use App\Service\Media\MediaStore;
use App\Service\Media\UploadValidator;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;
use Tests\Support\ImageFixtures;

/**
 * 04-back-office §7 : depuis une rubrique, une actualite, une œuvre ou une page,
 * on televerse une image DIRECTEMENT dans la mediatheque, qui devient la
 * couverture. Un `<input type="file">` ordinaire, traite a l'enregistrement :
 * aucune ligne de JavaScript (§12).
 *
 * Comme UploadTest, les repertoires de destination sont mis en bac a sable pour
 * ne pas ecrire dans les vrais.
 */
final class CouvertureUploadTest extends AdminTestCase
{
    private ImageFixtures $fixtures;
    private string $racineMedias;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new ImageFixtures();
        $this->racineMedias = $this->fixtures->path('cible');

        mkdir($this->racineMedias . '/storage/uploads', 0o775, true);
        mkdir($this->racineMedias . '/public/media', 0o775, true);

        $this->withService(
            MediaStore::class,
            fn (Container $c): MediaStore => new MediaStore(
                $c->get(UploadValidator::class),
                $c->get(ImageProcessor::class),
                $c->get(MediaAdminRepository::class),
                $c->get(RandomInterface::class),
                $c->get(ClockInterface::class),
                $this->racineMedias . '/storage/uploads',
                $this->racineMedias . '/public/media',
            ),
        );

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->fixtures->cleanup();
    }

    public function test_une_rubrique_recoit_sa_couverture_par_televersement(): void
    {
        $reponse = $this->avecCouverture('/cedric-taldu/admin/rubriques', 'couverture_fichier', [
            'titre_fr' => 'Encres',
        ]);

        $this->assertSame(302, $reponse->status);

        $cover = $this->valeur('SELECT cover_media_id FROM categories ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($cover);
        $this->assertSame(1, $this->compter('media'));
        $this->assertSame($cover, $this->valeur('SELECT id FROM media'));
    }

    public function test_une_actualite_recoit_son_image_a_la_une_par_televersement(): void
    {
        $reponse = $this->avecCouverture('/cedric-taldu/admin/actus', 'couverture_fichier', [
            'titre_fr' => 'Vernissage',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertNotNull($this->valeur('SELECT cover_media_id FROM posts ORDER BY id DESC LIMIT 1'));
        $this->assertSame(1, $this->compter('media'));
    }

    public function test_une_oeuvre_recoit_son_image_principale_par_televersement(): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $reponse = $this->avecCouverture('/cedric-taldu/admin/oeuvres', 'image_principale_fichier', [
            'rubrique' => (string) $rubrique,
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertNotNull($this->valeur('SELECT primary_media_id FROM artworks ORDER BY id DESC LIMIT 1'));
        $this->assertSame(1, $this->compter('media'));
    }

    public function test_une_page_recoit_sa_couverture_par_televersement(): void
    {
        $id = (int) $this->valeur("SELECT id FROM pages WHERE code = 'about'");

        $reponse = $this->avecCouverture('/cedric-taldu/admin/pages/' . $id, 'couverture_fichier', [
            'titre_fr' => 'À propos',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertNotNull($this->valeur('SELECT cover_media_id FROM pages WHERE id = ' . $id));
        $this->assertSame(1, $this->compter('media'));
    }

    public function test_un_fichier_de_couverture_invalide_est_refuse_sans_creer_l_entite(): void
    {
        // Un PHP deguise en JPEG doit etre refuse par MediaStore ; le formulaire
        // revient en 422 et la rubrique n'est pas creee.
        $reponse = $this->avecCouvertureFichier(
            '/cedric-taldu/admin/rubriques',
            'couverture_fichier',
            ['titre_fr' => 'Encres'],
            $this->fixtures->phpDeguiseEnJpeg(),
        );

        $this->assertSame(422, $reponse->status);
        $this->assertSame(0, $this->compter('categories'));
        $this->assertSame(0, $this->compter('media'));
    }

    // --------------------------------------------------------------- outils

    /**
     * @param array<string, string> $champs
     */
    private function avecCouverture(string $uri, string $champFichier, array $champs): \App\Core\Response
    {
        return $this->avecCouvertureFichier($uri, $champFichier, $champs, $this->fixtures->jpeg(800, 600));
    }

    /**
     * @param array<string, string> $champs
     */
    private function avecCouvertureFichier(
        string $uri,
        string $champFichier,
        array $champs,
        string $chemin,
    ): \App\Core\Response {
        return $this->requete('POST', $uri, post: [
            Csrf::FIELD => $this->jetonCsrf(),
            ...$champs,
        ], files: [
            $champFichier => [
                'name' => basename($chemin),
                'tmp_name' => $chemin,
                'size' => (int) filesize($chemin),
                'error' => UPLOAD_ERR_OK,
            ],
        ]);
    }

    private function compter(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false || $valeur === null ? null : (string) $valeur;
    }
}
