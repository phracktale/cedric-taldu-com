<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\UploadedFile;
use App\Repository\Admin\MediaAdminRepository;
use App\Service\Media\Exception\UploadRejected;
use App\Service\Media\ImageProcessor;
use App\Service\Media\MediaStore;
use App\Service\Media\UploadValidator;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FrozenClock;
use Tests\Support\Doubles\SequenceRandom;
use Tests\Support\ImageFixtures;

/**
 * Enregistrement d'un media de bout en bout : validation, re-encodage,
 * deduplication, rangement hors webroot, derives publics, ligne en base.
 *
 * Ce fichier eprouve surtout les COHERENCES ENTRE COUCHES, qu'aucun test
 * unitaire ne peut voir : que la ligne inseree pointe le fichier reellement
 * ecrit, que l'echec d'une etape n'en laisse aucune a moitie faite, et qu'un
 * doublon ne cree ni ligne ni fichier.
 */
final class MediaStoreTest extends DatabaseTestCase
{
    private ImageFixtures $fixtures;
    private MediaAdminRepository $depot;
    private MediaStore $store;
    private string $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new ImageFixtures();
        $this->racine = $this->fixtures->path('racine');

        mkdir($this->racine . '/storage/uploads', 0o775, true);
        mkdir($this->racine . '/public/media', 0o775, true);

        $this->depot = new MediaAdminRepository($this->pdo);
        $this->store = $this->storeAvec($this->alea());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->fixtures->cleanup();
    }

    // -------------------------------------------------------- cas nominal

    public function test_une_image_televersee_est_enregistree(): void
    {
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg(1600, 1200)));

        $media = $this->depot->findById($resultat->id);

        $this->assertNotNull($media);
        $this->assertFalse($resultat->wasDuplicate);
        $this->assertSame('image/jpeg', $media['mime']);
        $this->assertSame(1600, $media['width']);
        $this->assertSame(1200, $media['height']);
    }

    public function test_l_original_est_range_hors_du_webroot_en_deux_niveaux(): void
    {
        // 06-securite §5.6 : « Originaux stockes HORS WEBROOT, en arborescence a
        // deux niveaux. Seuls les derives regeneres sont publics. »
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg()));
        $media = $this->depot->findById($resultat->id);

        $this->assertNotNull($media);
        $this->assertMatchesRegularExpression(
            '#^uploads/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}\.jpg$#',
            (string) $media['storage_path'],
        );
        $this->assertFileExists($this->racine . '/storage/' . $media['storage_path']);
    }

    public function test_le_nom_du_fichier_est_tire_au_sort_et_l_extension_deduite_du_type_reel(): void
    {
        // 06-securite §5.5. Le client envoie « ../../evil.php » : ni le nom, ni
        // l'extension, ni le chemin ne doivent en garder trace.
        $resultat = $this->store->store(
            $this->televerse($this->fixtures->png(400, 300), '../../evil.php')
        );

        $media = $this->depot->findById($resultat->id);

        $this->assertNotNull($media);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $media['public_basename']);
        $this->assertStringEndsWith('.png', (string) $media['storage_path']);
        $this->assertStringNotContainsString('evil', (string) $media['storage_path']);
        $this->assertStringNotContainsString('..', (string) $media['storage_path']);
    }

    public function test_le_nom_d_origine_est_conserve_comme_simple_metadonnee(): void
    {
        $resultat = $this->store->store(
            $this->televerse($this->fixtures->jpeg(), '../../etc/Articulation — scan.JPG')
        );

        $media = $this->depot->findById($resultat->id);

        $this->assertNotNull($media);
        // basename() a fait son office : le nom affiche ne porte plus de chemin.
        $this->assertSame('Articulation — scan.JPG', $media['original_name']);
    }

    public function test_les_derives_publics_sont_engendres(): void
    {
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg(2400, 1800)));
        $media = $this->depot->findById($resultat->id);

        $this->assertNotNull($media);
        $base = (string) $media['public_basename'];

        foreach (\App\Domain\Catalog\Media::WIDTHS as $largeur) {
            foreach (\App\Domain\Catalog\Media::FORMATS as $format) {
                $this->assertFileExists(
                    $this->racine . '/public/media/' . $base . '-' . $largeur . '.' . $format
                );
            }
        }
    }

    public function test_un_texte_alternatif_est_enregistre_en_francais(): void
    {
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg()), 'Encre sur papier');

        $this->assertSame(
            'Encre sur papier',
            $this->depot->translationsOf($resultat->id)['fr']['alt'],
        );
    }

    // ------------------------------------------------------ deduplication

    public function test_la_meme_image_televersee_deux_fois_ne_cree_qu_une_ligne(): void
    {
        // 04-back-office §7 : deduplication par empreinte SHA-256.
        $premier = $this->store->store($this->televerse($this->fixtures->jpeg(800, 600, 'a.jpg')));
        $second = $this->store->store($this->televerse($this->fixtures->jpeg(800, 600, 'b.jpg')));

        $this->assertSame($premier->id, $second->id);
        $this->assertTrue($second->wasDuplicate);
        $this->assertSame(1, $this->depot->countAll());
    }

    public function test_deux_exports_differant_par_leurs_seules_metadonnees_se_confondent(): void
    {
        // Le cas reel : la meme photo exportee deux fois, avec deux horodatages
        // EXIF differents. Le re-encodage les a effaces, l'empreinte les
        // rapproche.
        $sans = $this->store->store($this->televerse($this->fixtures->jpeg(400, 300, 'sans.jpg')));
        $avec = $this->store->store($this->televerse($this->fixtures->jpegAvecGps('avec.jpg')));

        $this->assertSame($sans->id, $avec->id);
        $this->assertSame(1, $this->depot->countAll());
    }

    public function test_un_doublon_ne_laisse_aucun_fichier_supplementaire(): void
    {
        $this->store->store($this->televerse($this->fixtures->jpeg(800, 600, 'a.jpg')));
        $avant = $this->fichiersDe($this->racine . '/public/media');

        $this->store->store($this->televerse($this->fixtures->jpeg(800, 600, 'b.jpg')));

        $this->assertSame($avant, $this->fichiersDe($this->racine . '/public/media'));
        $this->assertSame([], $this->fichiersDe($this->racine . '/storage/uploads/tmp'));
    }

    // ------------------------------------------------------------- refus

    public function test_un_fichier_refuse_ne_laisse_ni_ligne_ni_fichier(): void
    {
        try {
            $this->store->store($this->televerse($this->fixtures->phpDeguiseEnJpeg()));
            $this->fail('Un script PHP déguisé doit être refusé.');
        } catch (UploadRejected) {
            // attendu
        }

        $this->assertSame(0, $this->depot->countAll());
        $this->assertSame([], $this->fichiersDe($this->racine . '/public/media'));
        $this->assertSame([], $this->fichiersDe($this->racine . '/storage/uploads/tmp'));
    }

    public function test_une_bombe_de_decompression_n_atteint_jamais_le_disque(): void
    {
        try {
            $this->store->store($this->televerse($this->fixtures->bombeDeDecompression()));
            $this->fail('Une bombe de décompression doit être refusée.');
        } catch (UploadRejected) {
            // attendu
        }

        $this->assertSame(0, $this->depot->countAll());
        $this->assertSame([], $this->fichiersDe($this->racine . '/public/media'));
    }

    // ---------------------------------------------------------- usages

    public function test_un_media_libre_n_est_employe_nulle_part(): void
    {
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg()));

        $this->assertSame(
            ['categories' => 0, 'artworks' => 0, 'galleries' => 0],
            $this->depot->usageOf($resultat->id),
        );
    }

    public function test_les_usages_d_un_media_sont_comptes(): void
    {
        // 04-back-office §7 : « Suppression refusee si le media est utilise ; la
        // liste des usages est affichee. »
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg()));

        $this->pdo->exec('INSERT INTO categories (cover_media_id, created_at, updated_at) VALUES ('
            . $resultat->id . ', NOW(), NOW())');

        $this->assertSame(1, $this->depot->usageOf($resultat->id)['categories']);
    }

    // ------------------------------------------------------------ effacement

    public function test_effacer_un_media_emporte_ses_fichiers(): void
    {
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg(800, 600)));
        $media = $this->depot->findById($resultat->id);
        $this->assertNotNull($media);

        $this->store->remove($resultat->id);

        $this->assertNull($this->depot->findById($resultat->id));
        $this->assertFileDoesNotExist($this->racine . '/storage/' . $media['storage_path']);
        $this->assertSame([], $this->fichiersDe($this->racine . '/public/media'));
    }

    public function test_effacer_un_media_inexistant_ne_fait_rien(): void
    {
        // Idempotence : un double clic sur « supprimer » ne doit pas produire
        // d'erreur.
        $this->store->remove(999999);

        $this->assertSame(0, $this->depot->countAll());
    }

    // ------------------------------------------------------- remplacement

    public function test_remplacer_une_image_conserve_l_identifiant_et_les_usages(): void
    {
        // 04-back-office §7 : remplacer l'image d'un media garde sa place — donc
        // son identifiant, et les couvertures qui pointent vers lui.
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg(1600, 1200, 'origine.jpg')));
        $this->pdo->exec('INSERT INTO categories (cover_media_id, created_at, updated_at) VALUES ('
            . $resultat->id . ', NOW(), NOW())');

        $this->store->replace($resultat->id, $this->televerse($this->fixtures->jpeg(800, 400, 'nouvelle.jpg')));

        $media = $this->depot->findById($resultat->id);
        $this->assertNotNull($media);
        $this->assertSame(800, $media['width']);
        $this->assertSame(400, $media['height']);
        $this->assertSame(1, $this->depot->usageOf($resultat->id)['categories']);
    }

    public function test_remplacer_regenere_les_derives_et_purge_les_orphelins(): void
    {
        // L'original passe de 2400 a 640 px : les derives -1024/-1600/-2400 de
        // l'ancienne image n'ont plus de source et doivent disparaitre.
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg(2400, 1800, 'grande.jpg')));
        $media = $this->depot->findById($resultat->id);
        $this->assertNotNull($media);
        $base = (string) $media['public_basename'];
        $this->assertFileExists($this->racine . '/public/media/' . $base . '-2400.jpg');

        $this->store->replace($resultat->id, $this->televerse($this->fixtures->jpeg(640, 480, 'petite.jpg')));

        $this->assertFileExists($this->racine . '/public/media/' . $base . '-640.jpg');
        $this->assertFileDoesNotExist($this->racine . '/public/media/' . $base . '-2400.jpg');
        $this->assertFileDoesNotExist($this->racine . '/public/media/' . $base . '-1024.jpg');
    }

    public function test_remplacer_par_une_image_deja_presente_ailleurs_est_refuse(): void
    {
        // Sinon deux medias porteraient la meme empreinte : la contrainte
        // d'unicite l'interdit, autant le dire clairement a l'artiste.
        $a = $this->store->store($this->televerse($this->fixtures->jpeg(500, 500, 'a.jpg')));
        $b = $this->store->store($this->televerse($this->fixtures->jpeg(300, 300, 'b.jpg')));

        try {
            $this->store->replace($b->id, $this->televerse($this->fixtures->jpeg(500, 500, 'copie-de-a.jpg')));
            $this->fail('Remplacer par une image déjà présente ailleurs doit être refusé.');
        } catch (UploadRejected $exception) {
            $this->assertSame(\App\Service\Media\UploadRejection::Duplicate, $exception->reason());
        }

        // Le media b n'a pas bouge : ni ses dimensions, ni son fichier.
        $media = $this->depot->findById($b->id);
        $this->assertNotNull($media);
        $this->assertSame(300, $media['width']);
    }

    public function test_remplacer_reinitialise_le_point_focal(): void
    {
        // Le point focal designait un endroit de l'ANCIENNE image ; sur la
        // nouvelle il ne veut plus rien dire, on le remet au centre (NULL).
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg(800, 600, 'avant.jpg')));
        $this->depot->updateFocalPoint($resultat->id, 200, 40);

        $this->store->replace($resultat->id, $this->televerse($this->fixtures->jpeg(800, 600, 'apres.jpg')));

        $media = $this->depot->findById($resultat->id);
        $this->assertNotNull($media);
        $this->assertNull($media['focal_x']);
        $this->assertNull($media['focal_y']);
    }

    // ---------------------------------------------------------- recadrage

    public function test_recadrer_met_a_jour_les_dimensions_et_les_derives(): void
    {
        $resultat = $this->store->store($this->televerse($this->fixtures->jpeg(1600, 1200, 'entiere.jpg')));

        $this->store->crop(
            $resultat->id,
            \App\Service\Media\CropRegion::fromFractions(0.25, 0.25, 0.5, 0.5),
        );

        $media = $this->depot->findById($resultat->id);
        $this->assertNotNull($media);
        $this->assertSame(800, $media['width']);
        $this->assertSame(600, $media['height']);
        $taille = getimagesize($this->racine . '/public/media/' . $media['public_basename'] . '-640.jpg');
        $this->assertIsArray($taille);
        $this->assertSame(640, $taille[0]);
    }

    public function test_recadrer_conserve_l_identifiant_les_traductions_et_le_copyright(): void
    {
        $resultat = $this->store->store(
            $this->televerse($this->fixtures->jpeg(1200, 1200, 'carree.jpg')),
            'Encre sur papier',
        );
        $this->depot->updateCopyright($resultat->id, '© Cédric Taldu');

        $this->store->crop(
            $resultat->id,
            \App\Service\Media\CropRegion::fromFractions(0.0, 0.0, 0.5, 1.0),
        );

        $media = $this->depot->findById($resultat->id);
        $this->assertNotNull($media);
        $this->assertSame('© Cédric Taldu', $media['copyright']);
        $this->assertSame('Encre sur papier', $this->depot->translationsOf($resultat->id)['fr']['alt']);
    }

    public function test_recadrer_un_media_inexistant_est_sans_effet(): void
    {
        $this->store->crop(999999, \App\Service\Media\CropRegion::fromFractions(0.0, 0.0, 0.5, 0.5));

        $this->assertSame(0, $this->depot->countAll());
    }

    // --------------------------------------------------------------- outils

    private function storeAvec(SequenceRandom $alea): MediaStore
    {
        return new MediaStore(
            new UploadValidator(),
            new ImageProcessor(),
            $this->depot,
            $alea,
            new FrozenClock(),
            $this->racine . '/storage/uploads',
            $this->racine . '/public/media',
        );
    }

    /**
     * Noms de fichiers previsibles : deux appels par enregistrement — un pour le
     * fichier temporaire, un pour le nom definitif.
     */
    private function alea(): SequenceRandom
    {
        $valeurs = [];

        for ($i = 1; $i <= 40; $i++) {
            $valeurs[] = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
        }

        return new SequenceRandom($valeurs);
    }

    private function televerse(string $chemin, ?string $nomClient = null): UploadedFile
    {
        return new UploadedFile(
            $nomClient ?? basename($chemin),
            $chemin,
            (int) filesize($chemin),
        );
    }

    /**
     * @return list<string>
     */
    private function fichiersDe(string $repertoire): array
    {
        $fichiers = array_map('basename', glob($repertoire . '/*') ?: []);
        sort($fichiers);

        return $fichiers;
    }
}
