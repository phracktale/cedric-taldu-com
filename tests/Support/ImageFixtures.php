<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Les fichiers de 07-tests-tdd §4, ENGENDRES plutot que commites.
 *
 * Trois raisons de ne pas les stocker en binaire dans le depot :
 *
 *  1. `.gitattributes` impose `* text=auto eol=lf`. Un fichier « PHP deguise en
 *     .jpg » serait normalise a la premiere prise, et la charge testee ne serait
 *     plus celle qu'on croit.
 *  2. Un depot qui contient des fichiers malveillants — meme inertes — declenche
 *     les antivirus des postes de developpement et les scanners de depot.
 *  3. Un blob binaire ne se relit pas. Ici, CHAQUE attaque est decrite par le
 *     code qui la fabrique : on voit ce qui est teste, et pourquoi.
 *
 * Les fichiers sont ecrits dans un repertoire temporaire, efface a la fin du
 * test qui l'a demande.
 *
 * La classe vit dans `Tests\Support` et NON dans un `Tests\Support\Fixtures` :
 * `tests/Support/fixtures/` existe deja, en minuscules, et porte des donnees.
 * Sous Windows les deux se confondent ; sur Thor, ou la casse est significative,
 * l'autoload PSR-4 ne trouverait pas la classe. Le piege est nomme dans
 * CLAUDE.md, et il s'est referme ici avant d'etre corrige.
 */
final class ImageFixtures
{
    /** Charge PHP employee par toutes les fixtures qui en portent une. */
    public const CHARGE_PHP = '<?php echo "compromis"; __halt_compiler();';

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? self::temporaryDirectory();

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Impossible de créer ' . $this->directory);
        }
    }

    public function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    /**
     * Efface le repertoire et tout son contenu.
     *
     * Recursif : les tests de derives creent des sous-repertoires, et un
     * rmdir() sur un repertoire non vide echoue en silence... jusqu'a ce que
     * PHPUnit, configure avec failOnWarning, en fasse un echec de test.
     */
    public function cleanup(): void
    {
        self::removeTree($this->directory);
    }

    private static function removeTree(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                self::removeTree($entry);
                continue;
            }

            unlink($entry);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    // ------------------------------------------------------- images valides

    public function jpeg(int $width = 800, int $height = 600, string $name = 'valide.jpg'): string
    {
        $file = $this->path($name);
        $image = $this->canvas($width, $height);

        if (!imagejpeg($image, $file, 88)) {
            throw new RuntimeException('Écriture JPEG impossible.');
        }

        imagedestroy($image);

        return $file;
    }

    public function png(int $width = 800, int $height = 600, string $name = 'valide.png'): string
    {
        $file = $this->path($name);
        $image = $this->canvas($width, $height);

        if (!imagepng($image, $file, 6)) {
            throw new RuntimeException('Écriture PNG impossible.');
        }

        imagedestroy($image);

        return $file;
    }

    public function webp(int $width = 800, int $height = 600, string $name = 'valide.webp'): string
    {
        $file = $this->path($name);
        $image = $this->canvas($width, $height);

        if (!imagewebp($image, $file, 80)) {
            throw new RuntimeException('Écriture WebP impossible.');
        }

        imagedestroy($image);

        return $file;
    }

    /**
     * PNG a canal alpha : eprouve que le re-encodage ne noircit pas la
     * transparence, defaut classique d'un imagecopy sur un fond non initialise.
     */
    public function pngTransparent(int $width = 400, int $height = 300, string $name = 'alpha.png'): string
    {
        $file = $this->path($name);
        $image = imagecreatetruecolor($width, $height);

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

        if ($transparent === false) {
            throw new RuntimeException('Palette GD épuisée.');
        }

        imagefilledrectangle($image, 0, 0, $width, $height, $transparent);
        imagepng($image, $file, 6);
        imagedestroy($image);

        return $file;
    }

    // --------------------------------------------------- fichiers refuses

    /**
     * Script PHP portant une extension d'image.
     *
     * Le cas le plus simple et le plus courant : l'extension ne dit rien du
     * contenu (06-securite §5.2).
     */
    public function phpDeguiseEnJpeg(string $name = 'malveillant.jpg'): string
    {
        return $this->write($name, self::CHARGE_PHP);
    }

    /**
     * Polyglotte GIF/PHP : en-tete GIF valide, code PHP a la suite.
     *
     * `getimagesize` le reconnait comme une image, et un serveur mal configure
     * l'executerait. Il doit etre refuse au type MIME : GIF n'est pas dans la
     * liste blanche de 06-securite §5.1.
     */
    public function polyglotteGifPhp(string $name = 'polyglotte.gif'): string
    {
        // GIF87a minimal : en-tete, ecran logique 1x1, table de couleurs, image.
        $gif = "GIF87a"
            . pack('vv', 1, 1)          // largeur, hauteur de l'ecran logique
            . "\x80\x00\x00"            // drapeaux, index de fond, rapport
            . "\x00\x00\x00\xFF\xFF\xFF" // table globale : noir, blanc
            . "\x2C" . pack('vvvv', 0, 0, 1, 1) . "\x00" // descripteur d'image
            . "\x02\x02\x44\x01\x00"    // donnees LZW
            . "\x3B";                   // fin de fichier

        return $this->write($name, $gif . self::CHARGE_PHP);
    }

    /**
     * Le cas le plus retors : un JPEG PARFAITEMENT VALIDE dont un segment de
     * commentaire porte du PHP.
     *
     * `finfo_file` rend image/jpeg, `getimagesize` rend des dimensions justes :
     * tous les controles de type le laissent passer, a juste titre. C'est le
     * RE-ENCODAGE qui le neutralise (06-securite §5.4), et rien d'autre. Sans
     * lui, le fichier atterrit sur le disque avec sa charge intacte.
     */
    public function jpegAvecPhpEnCommentaire(string $name = 'commentaire.jpg'): string
    {
        $source = (string) file_get_contents($this->jpeg(400, 300, 'source-commentaire.jpg'));

        $comment = self::CHARGE_PHP;
        // FF FE = segment COM ; la longueur s'inclut elle-meme.
        $segment = "\xFF\xFE" . pack('n', strlen($comment) + 2) . $comment;

        return $this->write($name, substr($source, 0, 2) . $segment . substr($source, 2));
    }

    /**
     * SVG : interdit sans condition (06-securite §5.1). C'est du XML, donc un
     * vecteur de script quand un navigateur le sert en image/svg+xml.
     */
    public function svg(string $name = 'vecteur.svg'): string
    {
        return $this->write($name, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">
              <script>alert(document.domain)</script>
              <image href="x" onerror="alert(1)"/>
            </svg>
            XML);
    }

    /**
     * JPEG portant des coordonnees GPS dans un segment EXIF.
     *
     * L'EXIF est construit octet par octet plutot que lu depuis un fichier
     * d'appareil photo : le test doit pouvoir affirmer que la latitude EST la,
     * puis qu'elle n'y est PLUS, sans dependre de l'extension exif — qui n'est
     * pas garantie sur un hebergement mutualise.
     */
    public function jpegAvecGps(string $name = 'geolocalise.jpg'): string
    {
        $source = (string) file_get_contents($this->jpeg(400, 300, 'source-gps.jpg'));

        return $this->write($name, substr($source, 0, 2) . self::exifGps() . substr($source, 2));
    }

    /**
     * « Bombe de decompression » : PNG dont l'en-tete DECLARE 50 000 x 50 000.
     *
     * Deux milliards et demi de pixels, soit une dizaine de gigaoctets une fois
     * decompresses par GD. Le fichier, lui, pese quelques dizaines d'octets.
     * C'est la raison pour laquelle 06-securite §5.3 impose de compter les
     * pixels AVANT le traitement, et non de faire confiance a la taille du
     * fichier.
     */
    public function bombeDeDecompression(int $width = 50000, int $height = 50000, string $name = 'bombe.png'): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return $this->write(
            $name,
            "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', $ihdr)
            . self::pngChunk('IDAT', "\x78\x9c\x63\x00\x00\x00\x02\x00\x01")
            . self::pngChunk('IEND', ''),
        );
    }

    public function vide(string $name = 'vide.jpg'): string
    {
        return $this->write($name, '');
    }

    /**
     * JPEG dont l'en-tete est intact mais dont les donnees sont coupees.
     *
     * `finfo_file` et `getimagesize` le declarent bon — ils ne lisent que
     * l'en-tete. Le decodage, lui, echoue. Le pipeline doit donc rendre un refus
     * propre et non une alerte PHP.
     */
    public function tronque(string $name = 'tronque.jpg'): string
    {
        $source = (string) file_get_contents($this->jpeg(800, 600, 'source-tronque.jpg'));

        return $this->write($name, substr($source, 0, (int) (strlen($source) * 0.55)));
    }

    /**
     * Fichier de la taille demandee, au contenu quelconque : pour eprouver la
     * borne de 25 Mo sans fabriquer une vraie image de 25 Mo.
     */
    public function volumineux(int $bytes, string $name = 'volumineux.jpg'): string
    {
        $file = $this->path($name);
        $handle = fopen($file, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Ouverture impossible : ' . $file);
        }

        // En-tete JPEG credible, puis du remplissage : ce qui est teste est la
        // taille, verifiee avant tout decodage.
        fwrite($handle, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00");
        fseek($handle, $bytes - 1);
        fwrite($handle, "\x00");
        fclose($handle);

        return $file;
    }

    // ------------------------------------------------------------- interne

    private function canvas(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        // Un degrade plutot qu'un aplat : un aplat se compresse en quelques
        // octets et ne prouve rien sur la qualite du re-encodage.
        for ($x = 0; $x < $width; $x++) {
            $teinte = (int) (255 * $x / max(1, $width - 1));
            $couleur = imagecolorallocate($image, $teinte, 255 - $teinte, 128);

            if ($couleur === false) {
                continue;
            }

            imageline($image, $x, 0, $x, $height, $couleur);
        }

        return $image;
    }

    private function write(string $name, string $contents): string
    {
        $file = $this->path($name);

        if (file_put_contents($file, $contents) === false) {
            throw new RuntimeException('Écriture impossible : ' . $file);
        }

        return $file;
    }

    /**
     * Segment APP1 « Exif » minimal contenant une latitude GPS.
     *
     * Structure TIFF petit-boutiste : en-tete (8 octets), IFD0 avec une seule
     * entree pointant l'IFD GPS, IFD GPS avec la reference et la latitude, puis
     * les trois rationnels degres/minutes/secondes.
     */
    private static function exifGps(): string
    {
        $ifdGpsOffset = 26;      // juste apres l'en-tete TIFF et l'IFD0
        $rationalOffset = 56;    // juste apres l'IFD GPS

        $tiff = "II" . pack('v', 42) . pack('V', 8);

        // IFD0 : une entree, tag 0x8825 (GPSInfo), type LONG, valeur = offset.
        $tiff .= pack('v', 1)
            . pack('v', 0x8825) . pack('v', 4) . pack('V', 1) . pack('V', $ifdGpsOffset)
            . pack('V', 0);

        // IFD GPS : reference « N » puis latitude sur trois rationnels.
        $tiff .= pack('v', 2)
            . pack('v', 0x0001) . pack('v', 2) . pack('V', 2) . "N\x00\x00\x00"
            . pack('v', 0x0002) . pack('v', 5) . pack('V', 3) . pack('V', $rationalOffset)
            . pack('V', 0);

        // 49° 53' 40" — Amiens.
        $tiff .= pack('VV', 49, 1) . pack('VV', 53, 1) . pack('VV', 40, 1);

        $payload = "Exif\x00\x00" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function temporaryDirectory(): string
    {
        return sys_get_temp_dir() . '/cedrictaldu-fixtures-' . bin2hex(random_bytes(6));
    }
}
