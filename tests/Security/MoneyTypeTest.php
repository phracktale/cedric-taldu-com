<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScanner;

/**
 * 06-securite (« Argent et stock »), src/CLAUDE.md et tests/CLAUDE.md :
 * aucun calcul monetaire en flottant dans src/.
 *
 * En binaire, 0,1 + 0,2 ne fait pas 0,3 : un site qui encaisse des paiements ne
 * peut pas se le permettre. Tout montant est un entier de centimes (Money), et
 * ce test lit le code source pour l'imposer — il n'attend pas qu'un arrondi
 * faux se glisse dans une facture.
 */
final class MoneyTypeTest extends TestCase
{
    /**
     * Money lui-meme manipule des entiers de centimes ; il est le SEUL a
     * connaitre la representation, et ses tests unitaires la couvrent.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'src/Domain/Money.php',
    ];

    /**
     * Noms de variables monetaires SANS AMBIGUITE.
     *
     * Volontairement absents :
     *  - « total », qui designe aussi un compte (pagination) ;
     *  - « ht », « ttc », « vat », « port », « unit », qui sont des sous-chaines
     *    de « height », « report », « private », « unite »… et produiraient des
     *    faux positifs sur du code d'image sans rapport avec l'argent.
     * Les montants ainsi ecartes restent couverts : ce sont des objets Money,
     * manipules par « ->cents », que le troisieme test attrape precisement.
     */
    private const MONEY_NAMES = 'price|amount|cents|montant|prix|subtotal|shipping';

    public function test_aucune_conversion_explicite_en_flottant_sur_un_montant(): void
    {
        // Un cast (float) ou floatval() sur une valeur monétaire est le chemin
        // le plus court vers l'erreur d'arrondi que Money existe pour fermer.
        $this->assertNoMatch(
            '/\((?:float|double)\)\s*\$?\w*(?:' . self::MONEY_NAMES . ')'
            . '|\bfloatval\s*\(\s*\$?\w*(?:' . self::MONEY_NAMES . ')/i',
            'Conversion en flottant d’une valeur monétaire (src/CLAUDE.md).',
        );
    }

    public function test_aucune_fonction_d_arrondi_flottant_sur_un_montant(): void
    {
        // round(), floor(), ceil() operent sur des flottants. Money::excludingVat
        // fait son arrondi bancaire en arithmetique ENTIERE ; nul autre calcul
        // monetaire ne doit passer par ces fonctions.
        $this->assertNoMatch(
            '/\b(?:round|floor|ceil)\s*\(\s*[^;)]*(?:' . self::MONEY_NAMES . ')/i',
            'Arrondi flottant sur une valeur monétaire : l’arrondi de Money est entier.',
        );
    }

    public function test_aucun_calcul_flottant_sur_les_centimes_hors_money(): void
    {
        // Money::cents est un entier. Le diviser ou le multiplier hors de Money
        // — la seule classe qui connait la representation — fabrique un flottant
        // et rouvre l'erreur d'arrondi. Money.php lui-meme fait tout en intdiv.
        $this->assertNoMatch(
            '#->cents\s*[/*]|[/*]\s*\$?[\w>-]*->cents#',
            'Arithmétique flottante sur ->cents hors de Money.',
        );
    }

    /**
     * Echoue en nommant chaque occurrence du motif dans les sources scannees.
     */
    private function assertNoMatch(string $pattern, string $message): void
    {
        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            if (preg_match_all($pattern, $source, $trouves, PREG_OFFSET_CAPTURE) >= 1) {
                foreach ($trouves[0] as [$extrait, $offset]) {
                    $ligne = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
                    $fautes[] = sprintf('%s:%d — %s', $chemin, $ligne, trim($extrait));
                }
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, [$message, ...$fautes]));
    }

    public function test_le_test_couvre_bien_les_sources_monetaires(): void
    {
        // Contre-epreuve : si le scan ne voyait aucun fichier parlant d'argent,
        // il passerait sans rien prouver.
        $sources = $this->sources();

        $this->assertArrayHasKey('src/Domain/Order/VatPolicy.php', $sources);
        $this->assertArrayHasKey('src/Service/Payment/CheckoutService.php', $sources);
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        $sources = SourceScanner::files('src');
        $this->assertNotSame([], $sources, 'Aucune source trouvée : le test ne prouverait rien.');

        $filtered = [];

        foreach ($sources as $chemin => $source) {
            if (in_array($chemin, self::ALLOWED, true)) {
                continue;
            }

            $filtered[$chemin] = SourceScanner::withoutComments($source);
        }

        return $filtered;
    }
}
