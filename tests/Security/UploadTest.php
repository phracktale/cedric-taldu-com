<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\ClockInterface;
use App\Core\Container;
use App\Core\RandomInterface;
use App\Repository\Admin\MediaAdminRepository;
use App\Service\Media\ImageProcessor;
use App\Service\Media\MediaStore;
use App\Service\Media\UploadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;
use Tests\Support\ImageFixtures;

/**
 * tests/CLAUDE.md : « Rejet des fichiers non-images, du PHP deguise en JPEG
 * (magic bytes + polyglotte GIF/PHP), du SVG, des fichiers hors limite de taille
 * et de dimensions ; verifie le re-encodage et la suppression des metadonnees
 * EXIF. »
 *
 * 08-lots : le lot 2 est fait quand « UploadTest passe INTEGRALEMENT ».
 *
 * Contrairement aux tests unitaires du validateur et du processeur, celui-ci
 * traverse la CHAINE REELLE : requete HTTP, session d'administration, jeton
 * CSRF, controleur, service, base, systeme de fichiers. Ce qu'il prouve n'est
 * pas qu'un composant refuse une charge, mais qu'aucun chemin ne permet de la
 * faire arriver sur le disque.
 *
 * Seuls les DEUX REPERTOIRES de destination sont deplaces vers un emplacement
 * temporaire. Ecrire dans les vrais effacerait les images de demonstration du
 * poste de travail a chaque `composer test` — une suite de tests ne doit pas
 * detruire les donnees de celui qui la lance. Le cablage reel des deux chemins
 * est verifie a part, par un test dedie.
 */
final class UploadTest extends AdminTestCase
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
        $this->fixtures->cleanup();

        parent::tearDown();
    }

    public function test_le_cablage_de_production_range_les_originaux_hors_du_webroot(): void
    {
        // Le pendant de l'isolement ci-dessus : ce test-ci ne televerse rien, il
        // verifie que config/services.php donne bien au MediaStore le couple de
        // repertoires de 06-securite §5.6 — l'un hors webroot, l'autre public.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/config/services.php');

        $this->assertStringContainsString("\$rootPath . '/storage/uploads'", $source);
        $this->assertStringContainsString("\$rootPath . '/public/media'", $source);
    }

    // -------------------------------------------------------------- refus

    /**
     * @return iterable<string, array{string}>
     */
    public static function fichiersInacceptables(): iterable
    {
        yield 'script PHP renommé en .jpg' => ['phpDeguiseEnJpeg'];
        yield 'polyglotte GIF/PHP' => ['polyglotteGifPhp'];
        yield 'SVG' => ['svg'];
        yield 'fichier vide' => ['vide'];
        yield 'fichier tronqué' => ['tronque'];
        yield 'bombe de décompression' => ['bombeDeDecompression'];
    }

    #[DataProvider('fichiersInacceptables')]
    public function test_un_fichier_inacceptable_n_entre_jamais_dans_la_mediatheque(string $fabrique): void
    {
        $chemin = $this->fixtures->{$fabrique}();

        $reponse = $this->televerse($chemin);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(0, $this->nombreDeMedias());
        $this->assertSame([], $this->derivesPublies());
    }

    #[DataProvider('fichiersInacceptables')]
    public function test_le_refus_est_journalise_sans_reveler_de_chemin(string $fabrique): void
    {
        // 06-securite §10 : « Journalisation des evenements de securite :
        // [...] uploads refuses. » Et §5.5 : le nom d'origine n'apparait que
        // comme metadonnee, jamais dans un chemin.
        $reponse = $this->televerse($this->fixtures->{$fabrique}());

        $statement = $this->pdo->query("SELECT meta FROM audit_log WHERE action = 'media.upload_rejected'");
        $this->assertNotFalse($statement);
        $traces = $statement->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertCount(1, $traces);
        $this->assertStringNotContainsString(sys_get_temp_dir(), $reponse->body);
        $this->assertStringNotContainsString('storage/', $reponse->body);
    }

    public function test_un_fichier_de_plus_de_vingt_cinq_megaoctets_est_refuse(): void
    {
        // 06-securite §5.3. Le fichier est creuse et non ecrit octet par octet :
        // le test ne doit pas couter vingt-cinq megaoctets d'ecriture reelle.
        $reponse = $this->televerse(
            $this->fixtures->volumineux(25 * 1024 * 1024 + 1)
        );

        $this->assertSame(422, $reponse->status);
        $this->assertSame(0, $this->nombreDeMedias());
    }

    public function test_un_depassement_de_la_limite_de_php_est_signale_a_l_artiste(): void
    {
        // PHP n'a alors transmis AUCUN fichier, seulement un code d'erreur. Dire
        // « aucun fichier reçu » enverrait l'artiste chercher un probleme qui
        // n'existe pas.
        $reponse = $this->requete('POST', self::MEDIAS, post: [
            \App\Core\Csrf::FIELD => $this->jetonCsrf(),
        ], files: [
            'images' => [
                'name' => ['enorme.jpg'],
                'tmp_name' => [''],
                'size' => [0],
                'error' => [UPLOAD_ERR_INI_SIZE],
            ],
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('25 Mo', $reponse->body);
    }

    // ------------------------------------------------------------- accepte

    public function test_un_jpeg_valide_entre_dans_la_mediatheque(): void
    {
        $reponse = $this->televerse($this->fixtures->jpeg(1200, 900));

        $this->assertSame(200, $reponse->status);
        $this->assertSame(1, $this->nombreDeMedias());
    }

    public function test_le_fichier_stocke_ne_porte_ni_le_nom_ni_l_extension_du_client(): void
    {
        // 06-securite §5.5 : nom aleatoire, extension deduite du type reel.
        $this->televerse($this->fixtures->png(400, 300), 'photo.jpg');

        $media = $this->dernierMedia();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $media['public_basename']);
        $this->assertStringEndsWith('.png', (string) $media['storage_path']);
        $this->assertStringNotContainsString('photo', (string) $media['storage_path']);
    }

    public function test_l_original_n_est_pas_servi_par_le_web(): void
    {
        // §5.6 : « Originaux stockes hors webroot [...] Seuls les derives
        // regeneres sont publics. »
        $this->televerse($this->fixtures->jpeg(800, 600));

        $media = $this->dernierMedia();

        $this->assertStringStartsWith('uploads/', (string) $media['storage_path']);
        $this->assertFileDoesNotExist(
            $this->racine() . '/public/media/' . basename((string) $media['storage_path'])
        );
    }

    // ----------------------------------------------- neutralisation des charges

    public function test_un_jpeg_valide_portant_du_php_est_accepte_mais_neutralise(): void
    {
        // LE test du lot. Le fichier est un JPEG parfaitement valide : finfo et
        // getimagesize le declarent bon, a juste titre. Il ENTRE donc dans la
        // mediatheque — et ce qui est ecrit sur le disque ne porte plus rien.
        $source = $this->fixtures->jpegAvecPhpEnCommentaire();
        $this->assertStringContainsString('<?php', (string) file_get_contents($source));

        $reponse = $this->televerse($source);

        $this->assertSame(200, $reponse->status);
        $this->assertSame(1, $this->nombreDeMedias());

        foreach ($this->fichiersEcrits() as $fichier) {
            $octets = (string) file_get_contents($fichier);

            $this->assertStringNotContainsString('<?php', $octets, basename($fichier));
            $this->assertStringNotContainsString('compromis', $octets, basename($fichier));
        }
    }

    public function test_la_geolocalisation_disparait_de_tous_les_fichiers_produits(): void
    {
        // 06-securite §5.4 et §9 : la latitude de l'atelier n'a rien a faire
        // dans une image publiee. Verifie sur les OCTETS : l'extension exif
        // n'est garantie ni sur le poste de developpement ni sur le mutualise.
        $source = $this->fixtures->jpegAvecGps();
        $this->assertStringContainsString("Exif\x00\x00", (string) file_get_contents($source));

        $this->televerse($source);

        $fichiers = $this->fichiersEcrits();
        $this->assertNotSame([], $fichiers);

        foreach ($fichiers as $fichier) {
            $this->assertStringNotContainsString(
                "Exif\x00\x00",
                (string) file_get_contents($fichier),
                basename($fichier),
            );
        }
    }

    public function test_les_derives_publics_sont_engendres_pour_chaque_largeur_et_format(): void
    {
        $this->televerse($this->fixtures->jpeg(2400, 1800));

        $base = (string) $this->dernierMedia()['public_basename'];
        $attendus = [];

        foreach (\App\Domain\Catalog\Media::WIDTHS as $largeur) {
            foreach (\App\Domain\Catalog\Media::FORMATS as $format) {
                $attendus[] = $base . '-' . $largeur . '.' . $format;
            }
        }

        sort($attendus);

        $this->assertSame($attendus, $this->derivesPublies());
    }

    // -------------------------------------------------------- deduplication

    public function test_la_meme_image_deux_fois_ne_cree_qu_une_entree(): void
    {
        $this->televerse($this->fixtures->jpeg(600, 400, 'a.jpg'));
        $reponse = $this->televerse($this->fixtures->jpeg(600, 400, 'b.jpg'));

        $this->assertSame(200, $reponse->status);
        $this->assertSame(1, $this->nombreDeMedias());
        // L'artiste doit SAVOIR que l'image etait deja la, sinon il croit avoir
        // echoue et recommence.
        $this->assertStringContainsString('déjà dans la médiathèque', $reponse->body);
    }

    // ------------------------------------------------------- controle d'acces

    public function test_le_televersement_est_ferme_sans_session(): void
    {
        $this->session->clear();

        $reponse = $this->televerse($this->fixtures->jpeg());

        $this->assertSame(302, $reponse->status);
        $this->assertSame(0, $this->nombreDeMedias());
    }

    public function test_le_televersement_est_refuse_sans_jeton_csrf(): void
    {
        $chemin = $this->fixtures->jpeg();

        $reponse = $this->requete('POST', self::MEDIAS, files: $this->champ($chemin));

        $this->assertSame(419, $reponse->status);
        $this->assertSame(0, $this->nombreDeMedias());
    }

    // --------------------------------------------------------------- outils

    private function televerse(string $chemin, ?string $nomClient = null): \App\Core\Response
    {
        return $this->requete(
            'POST',
            self::MEDIAS,
            post: [\App\Core\Csrf::FIELD => $this->jetonCsrf()],
            files: $this->champ($chemin, $nomClient),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function champ(string $chemin, ?string $nomClient = null): array
    {
        return [
            'images' => [
                'name' => [$nomClient ?? basename($chemin)],
                'tmp_name' => [$chemin],
                'size' => [(int) filesize($chemin)],
                'error' => [UPLOAD_ERR_OK],
            ],
        ];
    }

    private function racine(): string
    {
        return $this->racineMedias;
    }

    private function nombreDeMedias(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM media');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function dernierMedia(): array
    {
        $statement = $this->pdo->query('SELECT * FROM media ORDER BY id DESC LIMIT 1');
        $this->assertNotFalse($statement);

        /** @var array<string, mixed>|false $ligne */
        $ligne = $statement->fetch();
        $this->assertIsArray($ligne, 'Aucun média enregistré.');

        return $ligne;
    }

    /**
     * Tous les fichiers reellement ecrits par le televersement : l'original
     * archive ET les derives publics. La charge doit avoir disparu de TOUS.
     *
     * @return list<string>
     */
    private function fichiersEcrits(): array
    {
        $media = $this->dernierMedia();

        $fichiers = [$this->racine() . '/storage/' . $media['storage_path']];

        foreach (glob($this->racine() . '/public/media/' . $media['public_basename'] . '-*') ?: [] as $derive) {
            $fichiers[] = $derive;
        }

        return $fichiers;
    }

    /**
     * @return list<string>
     */
    private function derivesPublies(): array
    {
        $fichiers = array_map('basename', glob($this->racine() . '/public/media/*') ?: []);
        sort($fichiers);

        return $fichiers;
    }
}
