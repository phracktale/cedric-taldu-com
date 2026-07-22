<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\Request;
use App\Core\Response;
use App\Service\Payment\Exception\InvalidWebhookSignature;
use App\Service\Payment\PaymentEventHandler;
use App\Service\Payment\PaymentGateway;
use DateTimeImmutable;
use Throwable;

/**
 * `POST /webhooks/stripe` (03-boutique §6).
 *
 * La seule porte du site ouverte sans session ni jeton CSRF. Elle n'est tenue
 * que par la signature cryptographique du corps — d'ou le soin apporte a ce
 * qui suit.
 *
 * Trois regles gouvernent ce controleur :
 *
 * 1. Le CORPS BRUT est lu tel quel, avant toute normalisation. Re-encoder le
 *    JSON changerait les octets et invaliderait la signature — ou, pire, la
 *    validerait sur autre chose que ce que Stripe a signe.
 * 2. La reponse n'apprend RIEN a l'appelant : il n'est pas authentifie. Ni la
 *    forme attendue, ni l'etat interne, ni le motif du refus.
 * 3. Une exception pendant le traitement rend 500 pour que Stripe REESSAIE
 *    (§6.6). Repondre 200 sur un echec perdrait l'evenement pour toujours.
 */
final class StripeWebhookController
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentEventHandler $handler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Request $request): Response
    {
        $body = $request->rawBody ?? '';
        $signature = $request->header('Stripe-Signature') ?? '';

        try {
            $event = $this->gateway->verifyWebhook($body, $signature);
        } catch (InvalidWebhookSignature $e) {
            // 06-securite §10 : evenement de securite, journalise. Le motif
            // reste dans le journal ; la reponse, elle, ne dit rien.
            $this->logger->log(LogLevel::Warning, 'Webhook Stripe refusé', [
                'reason' => $e->getMessage(),
                'bytes' => strlen($body),
            ]);

            return self::opaque(400);
        }

        try {
            $outcome = $this->handler->handle($event, new DateTimeImmutable());
        } catch (Throwable $e) {
            // La transaction a ete annulee et `processed_at` est reste nul :
            // Stripe reessaiera, et le rejeu est inoffensif.
            $this->logger->log(LogLevel::Error, 'Traitement du webhook Stripe échoué', [
                'event_id' => $event->id,
                'type' => $event->type,
                'exception' => $e::class,
            ]);

            return self::opaque(500);
        }

        return self::opaque($outcome->httpStatus());
    }

    /**
     * Reponse minimale, sans corps exploitable et jamais mise en cache.
     *
     * Stripe ne lit que le code de statut. Tout ce qu'on ajouterait ne
     * profiterait qu'a quelqu'un qui cherche a comprendre la porte.
     */
    private static function opaque(int $status): Response
    {
        return (new Response('', $status, ['Content-Type' => 'text/plain; charset=UTF-8']))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
