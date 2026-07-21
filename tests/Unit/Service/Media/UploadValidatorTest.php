<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Media;

use App\Core\UploadedFile;
use App\Service\Media\Exception\UploadRejected;
use App\Service\Media\UploadRejection;
use App\Service\Media\UploadValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

/**
 * 06-securite §5, points 1 a 3 : types acceptes, type determine par le contenu
 * et non par l'extension, bornes de taille et de dimensions, comptage des pixels
 * AVANT traitement.
 *
 * Le validateur est la premiere des deux barrieres. La seconde est le
 * re-encodage : ce qui passe ici n'est pas encore repute sain, il est seulement
 * repute etre une image.
 */
final class UploadValidatorTest extends TestCase
{
    private ImageFixtures $fixtures;
    private UploadValidator $validateur;

    protected function setUp(): void
    {
        $this->fixtures = new ImageFixtures();
        $this->validateur = new UploadValidator();
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();
    }

    // ------------------------------------------------------------- accepte

    public function test_un_jpeg_valide_est_accepte(): void
    {
        $image = $this->validateur->validate($this->televerse($this->fixtures->jpeg(800, 600)));

        $this->assertSame('image/jpeg', $image->mime);
        $this->assertSame(800, $image->width);
        $this->assertSame(600, $image->height);
    }

    public function test_un_png_valide_est_accepte(): void
    {
        $image = $this->validateur->validate($this->televerse($this->fixtures->png(640, 480)));

        $this->assertSame('image/png', $image->mime);
    }

    public function test_un_webp_valide_est_accepte(): void
    {
        $image = $this->validateur->validate($this->televerse($this->fixtures->webp(640, 480)));

        $this->assertSame('image/webp', $image->mime);
    }

    public function test_l_extension_envoyee_par_le_client_n_a_aucune_influence(): void
    {
        // 06-securite §5.2 : le type vient de finfo_file ET de getimagesize,
        // jamais du nom. Un PNG nomme « .jpg » reste un PNG.
        $image = $this->validateur->validate(
            $this->televerse($this->fixtures->png(320, 240), 'photo.jpg')
        );

        $this->assertSame('image/png', $image->mime);
    }

    // --------------------------------------------------------------- refuse

    public function test_un_script_php_deguise_en_jpeg_est_refuse(): void
    {
        $this->assertRefus(
            UploadRejection::ForbiddenType,
            $this->fixtures->phpDeguiseEnJpeg(),
        );
    }

    public function test_un_polyglotte_gif_php_est_refuse(): void
    {
        // Le GIF est une image valide au sens de getimagesize, mais il n'est
        // pas dans la liste blanche de 06-securite §5.1 : refuse au type.
        $this->assertRefus(
            UploadRejection::ForbiddenType,
            $this->fixtures->polyglotteGifPhp(),
        );
    }

    public function test_un_svg_est_refuse(): void
    {
        // « SVG interdit (vecteur de XSS) », sans condition ni exception.
        $this->assertRefus(UploadRejection::ForbiddenType, $this->fixtures->svg());
    }

    public function test_un_svg_renomme_en_jpeg_est_refuse(): void
    {
        $this->assertRefus(
            UploadRejection::ForbiddenType,
            $this->fixtures->svg('vecteur-renomme.jpg'),
        );
    }

    public function test_un_fichier_vide_est_refuse(): void
    {
        $this->assertRefus(UploadRejection::Empty, $this->fixtures->vide());
    }

    public function test_une_bombe_de_decompression_est_refusee_avant_tout_decodage(): void
    {
        // 06-securite §5.3. Le fichier pese soixante-six octets et declare deux
        // milliards et demi de pixels. Ce sont ses DIMENSIONS qui l'arretent en
        // premier, et c'est le bon message : « dépasse 12 000 pixels de côté »
        // dit a l'artiste quoi faire, la ou « trop de pixels » resterait vague.
        $this->assertRefus(
            UploadRejection::TooLarge,
            $this->fixtures->bombeDeDecompression(),
        );
    }

    public function test_une_image_dans_les_bornes_de_dimension_peut_encore_etre_trop_lourde_a_traiter(): void
    {
        // Le cas que la seule borne de dimension NE COUVRE PAS : 12 000 x 12 000
        // est admissible au regard de 06-securite §5.3, mais represente
        // 144 megapixels, soit 576 Mo en couleurs vraies — plus du double du
        // memory_limit du conteneur. Sans budget de pixels, ce fichier passerait
        // la validation pour mourir en cours de decodage, sur une page blanche.
        $this->assertRefus(
            UploadRejection::TooManyPixels,
            $this->fixtures->bombeDeDecompression(
                UploadValidator::MAX_DIMENSION,
                UploadValidator::MAX_DIMENSION,
                'aux-bornes.png',
            ),
        );
    }

    public function test_un_fichier_tronque_est_refuse(): void
    {
        // GD decode un JPEG tronque SANS ALERTE, en comblant les lignes
        // manquantes en gris : rien, au decodage, ne signale le probleme. C'est
        // la marque de fin de fichier qui le trahit.
        $this->assertRefus(UploadRejection::Corrupt, $this->fixtures->tronque());
    }

    public function test_une_image_trop_large_est_refusee(): void
    {
        // Une bande de 50 000 x 100 ne fait que cinq megapixels : elle passe le
        // budget de pixels et doit etre arretee par la borne de dimension.
        $this->assertRefus(
            UploadRejection::TooLarge,
            $this->fixtures->bombeDeDecompression(50000, 100, 'bande.png'),
        );
    }

    public function test_une_image_a_la_limite_de_dimension_passe(): void
    {
        // La borne est inclusive : 12 000 px est accepte, 12 001 non.
        $limite = UploadValidator::MAX_DIMENSION;

        $accepte = $this->fixtures->bombeDeDecompression($limite, 100, 'limite.png');
        $refuse = $this->fixtures->bombeDeDecompression($limite + 1, 100, 'au-dela.png');

        $this->assertSame($limite, $this->validateur->validate($this->televerse($accepte))->width);
        $this->assertRefus(UploadRejection::TooLarge, $refuse);
    }

    public function test_un_fichier_de_plus_de_vingt_cinq_megaoctets_est_refuse(): void
    {
        $this->assertRefus(
            UploadRejection::TooHeavy,
            $this->fixtures->volumineux(UploadValidator::MAX_BYTES + 1),
        );
    }

    public function test_la_taille_est_verifiee_avant_toute_lecture_du_contenu(): void
    {
        // Le fichier volumineux n'est PAS une image : s'il etait refuse pour ce
        // motif, cela prouverait que le contenu a ete lu avant la taille — et
        // qu'un fichier de deux gigaoctets serait donc parcouru.
        $exception = $this->refus($this->fixtures->volumineux(UploadValidator::MAX_BYTES + 1));

        $this->assertSame(UploadRejection::TooHeavy, $exception->reason());
    }

    // ---------------------------------------------------- erreurs de PHP

    public function test_un_televersement_interrompu_est_refuse(): void
    {
        $fichier = new UploadedFile('photo.jpg', $this->fixtures->jpeg(), 0, UPLOAD_ERR_PARTIAL);

        $this->assertSame(UploadRejection::Failed, $this->refusDe($fichier)->reason());
    }

    public function test_un_depassement_de_la_limite_de_php_est_signale_comme_tel(): void
    {
        // upload_max_filesize depasse : PHP ne transmet aucun fichier. Dire
        // « fichier manquant » enverrait l'artiste chercher un probleme qui
        // n'existe pas.
        $fichier = new UploadedFile('photo.jpg', '', 0, UPLOAD_ERR_INI_SIZE);

        $this->assertSame(UploadRejection::TooHeavy, $this->refusDe($fichier)->reason());
    }

    public function test_l_absence_de_fichier_est_signalee(): void
    {
        $fichier = new UploadedFile('', '', 0, UPLOAD_ERR_NO_FILE);

        $this->assertSame(UploadRejection::Missing, $this->refusDe($fichier)->reason());
        $this->assertTrue($fichier->isMissing());
    }

    public function test_un_chemin_temporaire_inexistant_est_refuse(): void
    {
        $fichier = new UploadedFile('photo.jpg', $this->fixtures->path('jamais-ecrit.jpg'), 100);

        $this->assertSame(UploadRejection::Missing, $this->refusDe($fichier)->reason());
    }

    // ---------------------------------------------------------- messages

    public function test_chaque_motif_de_refus_porte_un_message_en_francais(): void
    {
        // Le message est affiche a l'artiste : il doit lui dire quoi faire, sans
        // jamais reveler un chemin serveur ni un detail d'implementation.
        foreach (UploadRejection::cases() as $motif) {
            $message = $motif->message();

            $this->assertNotSame('', $message, $motif->name);
            $this->assertStringNotContainsString('/', $message, $motif->name);
            $this->assertStringEndsWith('.', $message, $motif->name);
        }
    }

    // ---------------------------------------------------------------- outils

    private function televerse(string $chemin, ?string $nomClient = null): UploadedFile
    {
        return new UploadedFile(
            $nomClient ?? basename($chemin),
            $chemin,
            (int) filesize($chemin),
        );
    }

    private function assertRefus(UploadRejection $attendu, string $chemin): void
    {
        $this->assertSame($attendu, $this->refus($chemin)->reason());
    }

    private function refus(string $chemin): UploadRejected
    {
        return $this->refusDe($this->televerse($chemin));
    }

    private function refusDe(UploadedFile $fichier): UploadRejected
    {
        try {
            $this->validateur->validate($fichier);
        } catch (UploadRejected $exception) {
            return $exception;
        }

        $this->fail('Le fichier aurait dû être refusé.');
    }
}
