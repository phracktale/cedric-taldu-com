<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Media;

use App\Core\UploadedFile;
use App\Service\Media\Exception\UploadRejected;
use App\Service\Media\ImageProcessor;
use App\Service\Media\UploadRejection;
use App\Service\Media\UploadValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

/**
 * 06-securite §5.4 : « RE-ENCODAGE SYSTEMATIQUE par GD : l'image est decodee
 * puis reecrite. Cela detruit toute charge utile embarquee (polyglotte GIFAR,
 * PHP en commentaire EXIF) et supprime les metadonnees, y compris la
 * geolocalisation. »
 *
 * C'est la seule barriere qui protege contre un JPEG parfaitement valide portant
 * une charge : tous les controles de type le laissent passer, a juste titre.
 * D'ou deux tests qui verifient les OCTETS DE SORTIE, et pas seulement le fait
 * qu'un fichier ait ete produit.
 *
 * 07-tests-tdd §3 : « ImageProcessor | reel | On teste le vrai traitement GD sur
 * de petites images de fixture, y compris les fichiers malveillants. »
 */
final class ImageProcessorTest extends TestCase
{
    private ImageFixtures $fixtures;
    private UploadValidator $validateur;
    private ImageProcessor $processeur;

    protected function setUp(): void
    {
        $this->fixtures = new ImageFixtures();
        $this->validateur = new UploadValidator();
        $this->processeur = new ImageProcessor();
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();
    }

    // ------------------------------------------------- destruction des charges

    public function test_le_reencodage_detruit_le_php_cache_dans_un_commentaire_jpeg(): void
    {
        // Le fichier d'entree est un JPEG VALIDE : finfo et getimagesize le
        // declarent bon, et ils ont raison. Seul le re-encodage le neutralise.
        $source = $this->fixtures->jpegAvecPhpEnCommentaire();
        $this->assertStringContainsString('<?php', (string) file_get_contents($source));

        $sortie = $this->reencode($source);

        $this->assertStringNotContainsString('<?php', (string) file_get_contents($sortie));
        $this->assertStringNotContainsString('compromis', (string) file_get_contents($sortie));
    }

    public function test_le_reencodage_supprime_les_donnees_de_geolocalisation(): void
    {
        // 06-securite §5.4, et RGPD : la latitude de l'atelier n'a rien a faire
        // dans une image publiee. Verifie sur les OCTETS, sans dependre de
        // l'extension exif — absente du poste de developpement comme, souvent,
        // du mutualise.
        $source = $this->fixtures->jpegAvecGps();
        $this->assertStringContainsString("Exif\x00\x00", (string) file_get_contents($source));

        $octets = (string) file_get_contents($this->reencode($source));

        $this->assertStringNotContainsString("Exif\x00\x00", $octets);
        $this->assertSame(0, preg_match('/\xFF\xE1/', $octets), 'Aucun segment APP1 ne doit subsister.');
    }

    public function test_le_fichier_reencode_est_une_image_lisible(): void
    {
        // Detruire la charge en cassant l'image serait une reussite inutile.
        $sortie = $this->reencode($this->fixtures->jpeg(400, 300));

        $taille = getimagesize($sortie);

        $this->assertIsArray($taille);
        $this->assertSame(400, $taille[0]);
        $this->assertSame(300, $taille[1]);
    }

    // -------------------------------------------------------------- formats

    public function test_un_png_reste_un_png(): void
    {
        // Le format d'origine est conserve pour l'ORIGINAL archive : c'est le
        // generateur de derives qui produira les formats du web.
        $resultat = $this->traite($this->fixtures->png(320, 240));

        $this->assertSame('image/png', $resultat->mime);
        $this->assertSame('png', $resultat->extension);
    }

    public function test_un_webp_reste_un_webp(): void
    {
        $resultat = $this->traite($this->fixtures->webp(320, 240));

        $this->assertSame('image/webp', $resultat->mime);
        $this->assertSame('webp', $resultat->extension);
    }

    public function test_la_transparence_d_un_png_est_preservee(): void
    {
        // Defaut classique d'un re-encodage naif : imagecopy sur un fond non
        // initialise noircit tout ce qui etait transparent.
        $sortie = $this->reencode($this->fixtures->pngTransparent(80, 60));

        $image = imagecreatefrompng($sortie);
        $this->assertNotFalse($image);

        $couleur = imagecolorat($image, 40, 30);
        $this->assertSame(127, ($couleur >> 24) & 0x7F, 'Le pixel doit rester totalement transparent.');

        imagedestroy($image);
    }

    // ------------------------------------------------------------ empreinte

    public function test_le_meme_fichier_donne_toujours_la_meme_empreinte(): void
    {
        // 04-back-office §7 : « Deduplication par empreinte SHA-256. » Elle
        // porte sur le fichier RE-ENCODE (01-modele §2) : deux originaux
        // differant par leurs seules metadonnees sont le meme visuel, et ne
        // doivent occuper qu'une ligne.
        $premier = $this->traite($this->fixtures->jpeg(200, 150, 'a.jpg'));
        $second = $this->traite($this->fixtures->jpeg(200, 150, 'b.jpg'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $premier->checksum);
        $this->assertSame($premier->checksum, $second->checksum);
    }

    public function test_deux_images_differentes_ont_deux_empreintes(): void
    {
        $premier = $this->traite($this->fixtures->jpeg(200, 150, 'petite.jpg'));
        $second = $this->traite($this->fixtures->jpeg(300, 150, 'large.jpg'));

        $this->assertNotSame($premier->checksum, $second->checksum);
    }

    public function test_deux_images_identiques_aux_metadonnees_pres_se_confondent(): void
    {
        // C'est le cas reel de la deduplication : la meme photo exportee deux
        // fois, avec deux horodatages EXIF differents.
        $sans = $this->traite($this->fixtures->jpeg(400, 300, 'sans-exif.jpg'));
        $avec = $this->traite($this->fixtures->jpegAvecGps('avec-exif.jpg'));

        $this->assertSame($sans->checksum, $avec->checksum);
    }

    // ------------------------------------------------------- fichiers casses

    public function test_un_jpeg_tronque_est_refuse_proprement(): void
    {
        // Le fichier passe finfo et getimagesize — ils ne lisent que l'en-tete.
        // C'est le decodage qui echoue, et il doit rendre un refus type, pas une
        // alerte PHP : la suite est configuree avec failOnWarning.
        $fichier = $this->fixtures->tronque();

        $this->expectException(UploadRejected::class);

        $this->reencode($fichier);
    }

    public function test_le_motif_d_un_fichier_corrompu_est_explicite(): void
    {
        try {
            $this->reencode($this->fixtures->tronque());
            $this->fail('Un fichier tronqué doit être refusé.');
        } catch (UploadRejected $exception) {
            $this->assertSame(UploadRejection::Corrupt, $exception->reason());
        }
    }

    public function test_un_echec_de_decodage_ne_laisse_pas_de_fichier_derriere_lui(): void
    {
        // Sinon storage/uploads se remplit de fragments qu'aucune ligne de base
        // ne reference, et que personne ne nettoie jamais.
        $destination = $this->fixtures->path('sortie-partielle.jpg');

        try {
            $this->processeur->reencode(
                $this->validateur->validate($this->televerse($this->fixtures->tronque())),
                $destination,
            );
        } catch (UploadRejected) {
            // attendu
        }

        $this->assertFileDoesNotExist($destination);
    }

    // ------------------------------------------------------------- derives

    public function test_les_derives_reprennent_les_largeurs_et_formats_du_domaine(): void
    {
        // Media::WIDTHS et Media::FORMATS font autorite : le gabarit `picture`
        // engendre les <source> a partir des memes constantes, et un decalage
        // produirait des balises pointant des fichiers inexistants.
        $original = $this->reencode($this->fixtures->jpeg(2400, 1800));
        $repertoire = $this->fixtures->path('derives');
        mkdir($repertoire);

        $ecrits = $this->processeur->derivatives($original, $repertoire, 'abcdef');

        foreach (\App\Domain\Catalog\Media::WIDTHS as $largeur) {
            foreach (\App\Domain\Catalog\Media::FORMATS as $format) {
                $this->assertContains(
                    $repertoire . '/abcdef-' . $largeur . '.' . $format,
                    $ecrits,
                );
            }
        }
    }

    public function test_aucun_derive_n_agrandit_l_original(): void
    {
        // Agrandir n'ajoute pas d'information et fait telecharger plus d'octets
        // pour un resultat plus flou (Media::availableWidths()).
        $original = $this->reencode($this->fixtures->jpeg(800, 600));
        $repertoire = $this->fixtures->path('derives-petits');
        mkdir($repertoire);

        $ecrits = $this->processeur->derivatives($original, $repertoire, 'abcdef');

        foreach ($ecrits as $fichier) {
            $taille = getimagesize($fichier);
            $this->assertIsArray($taille);
            $this->assertLessThanOrEqual(800, $taille[0], basename($fichier));
        }
    }

    public function test_les_derives_conservent_le_rapport_d_aspect(): void
    {
        $original = $this->reencode($this->fixtures->jpeg(1600, 1200));
        $repertoire = $this->fixtures->path('derives-ratio');
        mkdir($repertoire);

        $this->processeur->derivatives($original, $repertoire, 'abcdef');

        $taille = getimagesize($repertoire . '/abcdef-640.jpg');

        $this->assertIsArray($taille);
        $this->assertSame(640, $taille[0]);
        $this->assertSame(480, $taille[1]);
    }

    public function test_une_image_plus_petite_que_la_plus_petite_largeur_garde_un_derive(): void
    {
        // Sans cela, une vignette de 200 px n'aurait aucune source et ne
        // s'afficherait nulle part.
        $original = $this->reencode($this->fixtures->jpeg(200, 150));
        $repertoire = $this->fixtures->path('derives-minuscule');
        mkdir($repertoire);

        $ecrits = $this->processeur->derivatives($original, $repertoire, 'abcdef');

        $this->assertNotSame([], $ecrits);
        $this->assertFileExists($repertoire . '/abcdef-320.jpg');
    }

    // ------------------------------------------------------------ recadrage

    public function test_le_recadrage_produit_une_image_aux_dimensions_de_la_zone(): void
    {
        // La zone couvre la moitie centrale : sur un original 1600 x 1200, on
        // attend 800 x 600.
        $original = $this->reencode($this->fixtures->jpeg(1600, 1200));
        $destination = $this->fixtures->path('recadre.jpg');

        $resultat = $this->processeur->crop(
            $original,
            \App\Service\Media\CropRegion::fromFractions(0.25, 0.25, 0.5, 0.5),
            $destination,
        );

        $this->assertSame(800, $resultat->width);
        $this->assertSame(600, $resultat->height);
        $taille = getimagesize($destination);
        $this->assertIsArray($taille);
        $this->assertSame(800, $taille[0]);
        $this->assertSame(600, $taille[1]);
    }

    public function test_le_recadrage_conserve_le_format_de_l_original(): void
    {
        $original = $this->reencode($this->fixtures->png(400, 400));

        $resultat = $this->processeur->crop(
            $original,
            \App\Service\Media\CropRegion::fromFractions(0.0, 0.0, 0.5, 0.5),
            $this->fixtures->path('recadre.png'),
        );

        $this->assertSame('image/png', $resultat->mime);
        $this->assertSame('png', $resultat->extension);
    }

    public function test_le_recadrage_change_l_empreinte(): void
    {
        // Une image recadree est une image differente : son empreinte doit
        // changer, sinon la deduplication la confondrait avec l'originale.
        $original = $this->reencode($this->fixtures->jpeg(800, 600));
        $avant = hash_file('sha256', $original);

        $resultat = $this->processeur->crop(
            $original,
            \App\Service\Media\CropRegion::fromFractions(0.1, 0.1, 0.5, 0.5),
            $this->fixtures->path('recadre-empreinte.jpg'),
        );

        $this->assertNotSame($avant, $resultat->checksum);
    }

    // ---------------------------------------------------------------- outils

    private function televerse(string $chemin): UploadedFile
    {
        return new UploadedFile(basename($chemin), $chemin, (int) filesize($chemin));
    }

    private function traite(string $chemin): \App\Service\Media\ProcessedImage
    {
        return $this->processeur->reencode(
            $this->validateur->validate($this->televerse($chemin)),
            $this->fixtures->path('reencode-' . basename($chemin)),
        );
    }

    private function reencode(string $chemin): string
    {
        return $this->traite($chemin)->path;
    }
}
