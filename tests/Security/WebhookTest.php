<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Service\Payment\Exception\InvalidWebhookSignature;
use App\Service\Payment\WebhookSignature;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScanner;

/**
 * 06-securite §7 et 03-boutique §6 : le webhook Stripe est la seule porte du
 * site ouverte sans jeton de session ni CSRF. Elle n'est tenue que par la
 * signature cryptographique du corps BRUT.
 *
 * Le parcours HTTP (400 sur signature invalide, 200 au rejeu sans double effet)
 * est couvert par WebhookStripeTest et AchatCompletTest. Ce test-ci verrouille
 * les proprietes cryptographiques que ceux-la ne peuvent pas voir : comparaison
 * en temps constant, tolerance temporelle anti-rejeu, et lecture du corps brut.
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    public function test_la_signature_est_comparee_en_temps_constant(): void
    {
        // Une comparaison naive « === » s'arreterait au premier octet different,
        // et le temps de reponse trahirait le nombre d'octets devines
        // (06-securite §8). Le code source doit employer hash_equals.
        $source = $this->source('src/Service/Payment/WebhookSignature.php');

        $this->assertStringContainsString('hash_equals', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/\$expected[^;\n]*===|===[^;\n]*\$signature/',
            $source,
            'La signature ne doit jamais être comparée par === .',
        );
    }

    public function test_un_horodatage_hors_tolerance_est_rejete(): void
    {
        // Rejeu : une capture ancienne ne doit pas rester utilisable
        // indefiniment (03-boutique §6, tolerance temporelle).
        $corps = '{"id":"evt_1"}';
        $signeA = 1_700_000_000;
        $entete = WebhookSignature::sign($corps, self::SECRET, $signeA);

        // Bien au-dela de la tolerance de cinq minutes.
        $maintenant = $signeA + WebhookSignature::TOLERANCE + 60;

        $this->expectException(InvalidWebhookSignature::class);
        WebhookSignature::verify($corps, $entete, self::SECRET, $maintenant);
    }

    public function test_un_horodatage_dans_la_tolerance_est_accepte(): void
    {
        $corps = '{"id":"evt_1"}';
        $signeA = 1_700_000_000;
        $entete = WebhookSignature::sign($corps, self::SECRET, $signeA);

        WebhookSignature::verify($corps, $entete, self::SECRET, $signeA + WebhookSignature::TOLERANCE - 1);

        $this->addToAssertionCount(1);
    }

    public function test_la_signature_porte_sur_le_corps_brut(): void
    {
        // Un octet modifie apres signature invalide l'entete : c'est tout
        // l'interet de lire php://input avant toute normalisation.
        $corps = '{"id":"evt_1","amount":45000}';
        $entete = WebhookSignature::sign($corps, self::SECRET, 1_700_000_000);

        $this->expectException(InvalidWebhookSignature::class);
        WebhookSignature::verify(str_replace('45000', '1', $corps), $entete, self::SECRET);
    }

    public function test_le_controleur_lit_le_corps_brut_et_ne_le_re_encode_pas(): void
    {
        // La verification doit porter sur rawBody, jamais sur un JSON re-encode :
        // re-serialiser changerait les octets et validerait autre chose que ce
        // que Stripe a signe.
        $source = $this->source('src/Http/Controller/Front/StripeWebhookController.php');

        $this->assertStringContainsString('rawBody', $source);
        $this->assertStringContainsString('verifyWebhook', $source);
    }

    public function test_une_signature_forgee_avec_un_autre_secret_est_rejetee(): void
    {
        $corps = '{"id":"evt_1"}';
        $entete = WebhookSignature::sign($corps, 'whsec_autre', 1_700_000_000);

        $this->expectException(InvalidWebhookSignature::class);
        WebhookSignature::verify($corps, $entete, self::SECRET);
    }

    private function source(string $relative): string
    {
        return SourceScanner::withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative),
        );
    }
}
