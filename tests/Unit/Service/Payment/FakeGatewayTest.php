<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Payment;

use App\Service\Payment\Exception\InvalidWebhookSignature;
use App\Service\Payment\FakeGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Double de la passerelle de paiement (07-tests-tdd §3).
 *
 * Aucun test n'appelle le reseau. Mais la VERIFICATION DE SIGNATURE, elle, est
 * la vraie : le double rejoue le schema de Stripe — HMAC-SHA256 sur
 * « <horodatage>.<corps brut> » — avec un secret de test. Un double qui se
 * contenterait de repondre « oui » ferait passer WebhookTest sans rien prouver,
 * et c'est precisement le garde-fou le plus important du lot.
 */
#[CoversClass(FakeGateway::class)]
final class FakeGatewayTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new FakeGateway(self::SECRET);
    }

    // -------------------------------------------------------- creation

    public function test_une_session_porte_une_url_et_un_identifiant(): void
    {
        $session = $this->gateway->createCheckout(
            reference: 'CT-2026-0001',
            orderId: 1,
            lines: [['label' => 'Articulation', 'amount' => 45000, 'quantity' => 1]],
            shippingCents: 900,
            currency: 'EUR',
            customerEmail: 'acheteur@example.test',
            successUrl: 'https://example.test/fr/commande/confirmation/CT-2026-0001?t=abc',
            cancelUrl: 'https://example.test/fr/panier',
            expiresAt: 1_800_000_000,
        );

        $this->assertNotSame('', $session->id);
        $this->assertStringStartsWith('https://', $session->url);
        $this->assertSame(1_800_000_000, $session->expiresAt);
    }

    public function test_deux_sessions_ont_des_identifiants_differents(): void
    {
        $une = $this->creerSession('CT-2026-0001');
        $deux = $this->creerSession('CT-2026-0002');

        $this->assertNotSame($une->id, $deux->id);
    }

    public function test_la_session_retient_ce_qui_lui_a_ete_demande(): void
    {
        // Sert a PriceIntegrityTest : la somme envoyee a Stripe doit etre celle
        // recalculee en base, jamais celle venue du client.
        $this->creerSession('CT-2026-0001');

        $demande = $this->gateway->lastCheckout();

        $this->assertNotNull($demande);
        $this->assertSame('CT-2026-0001', $demande['reference']);
        $this->assertSame(45900, $demande['total']);
    }

    // --------------------------------------------------- signature

    public function test_un_evenement_correctement_signe_est_accepte(): void
    {
        $corps = $this->corps('evt_1', 'checkout.session.completed');
        $entete = $this->gateway->signPayload($corps, 1_700_000_000);

        $evenement = $this->gateway->verifyWebhook($corps, $entete);

        $this->assertSame('evt_1', $evenement->id);
        $this->assertSame('checkout.session.completed', $evenement->type);
    }

    public function test_une_signature_invalide_est_rejetee(): void
    {
        // 03-boutique §6 : signature invalide -> 400, aucun effet.
        $corps = $this->corps('evt_1', 'checkout.session.completed');

        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook($corps, 't=1700000000,v1=' . str_repeat('0', 64));
    }

    public function test_un_corps_modifie_apres_signature_est_rejete(): void
    {
        // Le cœur de la protection : la signature porte sur le CORPS BRUT.
        // Changer un montant apres coup doit invalider l'entete.
        $corps = $this->corps('evt_1', 'checkout.session.completed');
        $entete = $this->gateway->signPayload($corps, 1_700_000_000);

        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook(str_replace('45900', '100', $corps), $entete);
    }

    public function test_un_entete_absent_est_rejete(): void
    {
        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook($this->corps('evt_1', 'checkout.session.completed'), '');
    }

    public function test_un_entete_mal_forme_est_rejete(): void
    {
        $corps = $this->corps('evt_1', 'checkout.session.completed');

        foreach (['n’importe quoi', 't=abc,v1=def', 'v1=' . str_repeat('a', 64)] as $entete) {
            try {
                $this->gateway->verifyWebhook($corps, $entete);
                $this->fail("En-tête accepté à tort : {$entete}");
            } catch (InvalidWebhookSignature) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_une_signature_signee_avec_un_autre_secret_est_rejetee(): void
    {
        // Le cas d'un attaquant qui connait le schema mais pas le secret.
        $corps = $this->corps('evt_1', 'checkout.session.completed');
        $entete = (new FakeGateway('whsec_autre_secret'))->signPayload($corps, 1_700_000_000);

        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook($corps, $entete);
    }

    public function test_un_corps_qui_n_est_pas_du_json_est_rejete(): void
    {
        $entete = $this->gateway->signPayload('pas du json', 1_700_000_000);

        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook('pas du json', $entete);
    }

    public function test_l_empreinte_du_corps_est_rendue_avec_l_evenement(): void
    {
        // stripe_events.payload_hash : de quoi reconnaitre deux livraisons
        // reellement identiques.
        $corps = $this->corps('evt_1', 'checkout.session.completed');

        $evenement = $this->gateway->verifyWebhook($corps, $this->gateway->signPayload($corps, 1_700_000_000));

        $this->assertSame(hash('sha256', $corps), $evenement->payloadHash);
    }

    public function test_l_evenement_expose_la_reference_de_commande(): void
    {
        $corps = $this->corps('evt_1', 'checkout.session.completed');

        $evenement = $this->gateway->verifyWebhook($corps, $this->gateway->signPayload($corps, 1_700_000_000));

        $this->assertSame('CT-2026-0001', $evenement->clientReferenceId);
        $this->assertSame('paid', $evenement->paymentStatus);
        $this->assertSame('cs_test_1', $evenement->sessionId);
    }

    // ------------------------------------------------------------ assistance

    private function creerSession(string $reference): \App\Service\Payment\CheckoutSession
    {
        return $this->gateway->createCheckout(
            reference: $reference,
            orderId: 1,
            lines: [['label' => 'Articulation', 'amount' => 45000, 'quantity' => 1]],
            shippingCents: 900,
            currency: 'EUR',
            customerEmail: 'acheteur@example.test',
            successUrl: 'https://example.test/fr/commande/confirmation/' . $reference . '?t=abc',
            cancelUrl: 'https://example.test/fr/panier',
            expiresAt: 1_800_000_000,
        );
    }

    private function corps(string $eventId, string $type): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => 'cs_test_1',
                    'client_reference_id' => 'CT-2026-0001',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_1',
                    'amount_total' => 45900,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
