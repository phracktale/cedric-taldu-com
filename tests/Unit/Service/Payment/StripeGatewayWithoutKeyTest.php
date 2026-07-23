<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Payment;

use App\Service\Payment\Exception\InvalidWebhookSignature;
use App\Service\Payment\StripeCheckoutGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La verification de webhook ne doit PAS dependre du client Stripe.
 *
 * Découvert au déploiement preprod : sans `STRIPE_SECRET_KEY`, le SDK refuse
 * `new StripeClient('')` — et `POST /webhooks/stripe` renvoyait un 500 fuyant
 * une exception Stripe, au lieu d'un 400 propre. Or `verifyWebhook` n'utilise
 * que la signature HMAC : la construction du client doit donc être paresseuse,
 * repoussée jusqu'à un vrai `createCheckout`.
 */
#[CoversClass(StripeCheckoutGateway::class)]
final class StripeGatewayWithoutKeyTest extends TestCase
{
    public function test_la_passerelle_se_construit_sans_cle_d_api(): void
    {
        // Aucune exception : la clé vide n'est pas touchée tant qu'on ne paie
        // pas. Le site — et son webhook — démarre sans paiement configuré.
        $gateway = new StripeCheckoutGateway('', 'whsec_test');

        $this->assertInstanceOf(StripeCheckoutGateway::class, $gateway);
    }

    public function test_un_webhook_est_verifie_sans_client_stripe(): void
    {
        // Le cœur du correctif : verifyWebhook n'a besoin que du secret de
        // signature, jamais de la clé d'API. Une signature invalide donne une
        // exception de SIGNATURE, pas une erreur « api_key vide » du SDK.
        $gateway = new StripeCheckoutGateway('', 'whsec_test');

        $this->expectException(InvalidWebhookSignature::class);
        $gateway->verifyWebhook('{}', 't=1,v1=' . str_repeat('0', 64));
    }

    public function test_un_webhook_correctement_signe_est_accepte_sans_cle_d_api(): void
    {
        $gateway = new StripeCheckoutGateway('', 'whsec_test');

        $corps = json_encode([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
        ], JSON_THROW_ON_ERROR);

        $signature = \App\Service\Payment\WebhookSignature::sign($corps, 'whsec_test', time());

        $event = $gateway->verifyWebhook($corps, $signature);

        $this->assertSame('evt_1', $event->id);
    }
}
