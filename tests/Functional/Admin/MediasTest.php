<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Core\Container;
use App\Core\ClockInterface;
use App\Core\Csrf;
use App\Core\RandomInterface;
use App\Core\Response;
use App\Repository\Admin\MediaAdminRepository;
use App\Service\Media\ImageProcessor;
use App\Service\Media\MediaStore;
use App\Service\Media\UploadValidator;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\UserFactory;
use Tests\Support\ImageFixtures;

/**
 * 04-back-office §7 : la mediatheque edite une image — texte alternatif et
 * legende par langue, copyright, point focal — la remplace et la recadre.
 *
 * Comme UploadTest, les deux repertoires de destination sont deplaces vers un
 * emplacement temporaire : ecrire dans les vrais effacerait les images de
 * demonstration du poste a chaque `composer test`.
 */
final class MediasTest extends AdminTestCase
{
    private const MEDIAS = '/cedric-taldu/admin/medias';

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

    private function depot(): MediaAdminRepository
    {
        return new MediaAdminRepository($this->pdo);
    }

    // ------------------------------------------------------------- fiche

    public function test_la_fiche_montre_les_champs_par_langue_et_le_recadrage(): void
    {
        $id = (new MediaFactory($this->pdo))->named('fiche')->translated('fr', 'Encre')->create();

        $reponse = $this->get(self::MEDIAS . '/' . $id);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="alt_fr"', $reponse->body);
        $this->assertStringContainsString('name="alt_en"', $reponse->body);
        $this->assertStringContainsString('name="copyright"', $reponse->body);
        $this->assertStringContainsString('name="focal_x"', $reponse->body);
        $this->assertStringContainsString('/' . $id . '/recadrage', $reponse->body);
        $this->assertStringContainsString('/' . $id . '/remplacement', $reponse->body);
    }

    public function test_la_fiche_d_une_image_inconnue_repond_404(): void
    {
        $this->assertSame(404, $this->get(self::MEDIAS . '/999999')->status);
    }

    // ------------------------------------------------------------- edition

    public function test_l_edition_enregistre_le_texte_par_langue_le_copyright_et_le_focal(): void
    {
        $id = (new MediaFactory($this->pdo))->named('maj')->translated('fr', 'Ancien')->create();

        $reponse = $this->postAvecJeton(self::MEDIAS . '/' . $id, [
            'alt_fr' => 'Encre sur papier',
            'alt_en' => 'Ink on paper',
            'caption_fr' => 'Atelier, 2026',
            'copyright' => '© Cédric Taldu',
            'focal_x' => '40',
            'focal_y' => '30',
        ]);

        $this->assertSame(302, $reponse->status);

        $traductions = $this->depot()->translationsOf($id);
        $this->assertSame('Encre sur papier', $traductions['fr']['alt']);
        $this->assertSame('Ink on paper', $traductions['en']['alt']);
        $this->assertSame('Atelier, 2026', $traductions['fr']['caption']);

        $media = $this->depot()->findById($id);
        $this->assertNotNull($media);
        $this->assertSame('© Cédric Taldu', $media['copyright']);
        $this->assertSame(40, $media['focal_x']);
        $this->assertSame(30, $media['focal_y']);
    }

    public function test_un_copyright_vide_efface_le_credit(): void
    {
        $id = (new MediaFactory($this->pdo))->named('sans-credit')->withCopyright('© Ancien')->create();

        $this->postAvecJeton(self::MEDIAS . '/' . $id, ['alt_fr' => 'Encre', 'copyright' => '']);

        $media = $this->depot()->findById($id);
        $this->assertNotNull($media);
        $this->assertNull($media['copyright']);
    }

    public function test_une_langue_laissee_vide_n_est_pas_enregistree(): void
    {
        $id = (new MediaFactory($this->pdo))
            ->named('bilingue')
            ->translated('fr', 'Encre')
            ->translated('en', 'Ink')
            ->create();

        $this->postAvecJeton(self::MEDIAS . '/' . $id, ['alt_fr' => 'Encre', 'alt_en' => '', 'caption_en' => '']);

        $this->assertArrayNotHasKey('en', $this->depot()->translationsOf($id));
    }

    // ------------------------------------------------------------ recadrage

    public function test_le_recadrage_refuse_une_zone_incoherente(): void
    {
        // Une zone qui commence a 0,8 et fait 0,5 de large sort de l'image : elle
        // doit etre refusee proprement, sans 500 ni erreur GD.
        $id = (new MediaFactory($this->pdo))->named('zone')->create();

        $reponse = $this->postAvecJeton(self::MEDIAS . '/' . $id . '/recadrage', [
            'crop_x' => '0.8',
            'crop_y' => '0.1',
            'crop_w' => '0.5',
            'crop_h' => '0.5',
        ]);

        $this->assertSame(422, $reponse->status);
    }

    public function test_le_recadrage_reduit_les_dimensions_et_regenere_les_derives(): void
    {
        $id = $this->uploader($this->fixtures->jpeg(1600, 1200, 'entiere.jpg'));

        $reponse = $this->postAvecJeton(self::MEDIAS . '/' . $id . '/recadrage', [
            'crop_x' => '0.25',
            'crop_y' => '0.25',
            'crop_w' => '0.5',
            'crop_h' => '0.5',
        ]);

        $this->assertSame(302, $reponse->status);

        $media = $this->depot()->findById($id);
        $this->assertNotNull($media);
        $this->assertSame(800, $media['width']);
        $this->assertSame(600, $media['height']);
    }

    // ---------------------------------------------------------- remplacement

    public function test_le_remplacement_change_l_image_en_conservant_la_place(): void
    {
        $id = $this->uploader($this->fixtures->jpeg(800, 600, 'avant.jpg'));

        $reponse = $this->remplacer($id, $this->fixtures->jpeg(400, 300, 'apres.jpg'));

        $this->assertSame(302, $reponse->status);

        $media = $this->depot()->findById($id);
        $this->assertNotNull($media);
        $this->assertSame(400, $media['width']);
        $this->assertSame(300, $media['height']);
    }

    public function test_le_remplacement_sans_fichier_est_refuse(): void
    {
        $id = (new MediaFactory($this->pdo))->named('rien')->create();

        $reponse = $this->postAvecJeton(self::MEDIAS . '/' . $id . '/remplacement');

        $this->assertSame(422, $reponse->status);
    }

    // --------------------------------------------------------------- outils

    private function uploader(string $chemin): int
    {
        $this->requete('POST', self::MEDIAS, post: [Csrf::FIELD => $this->jetonCsrf()], files: [
            'images' => [
                'name' => [basename($chemin)],
                'tmp_name' => [$chemin],
                'size' => [(int) filesize($chemin)],
                'error' => [UPLOAD_ERR_OK],
            ],
        ]);

        $statement = $this->pdo->query('SELECT id FROM media ORDER BY id DESC LIMIT 1');
        $this->assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function remplacer(int $id, string $chemin): Response
    {
        return $this->requete('POST', self::MEDIAS . '/' . $id . '/remplacement', post: [
            Csrf::FIELD => $this->jetonCsrf(),
        ], files: [
            'image' => [
                'name' => basename($chemin),
                'tmp_name' => $chemin,
                'size' => (int) filesize($chemin),
                'error' => UPLOAD_ERR_OK,
            ],
        ]);
    }
}
