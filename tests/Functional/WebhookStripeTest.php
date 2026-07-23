<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Response;
use App\Service\Payment\FakeGateway;
use App\Service\Payment\PaymentGateway;
use Tests\Support\FunctionalTestCase;

/**
 * `POST /webhooks/stripe` (03-boutique §6).
 *
 * Route exemptee de CSRF et de localisation, jamais mise en cache. C'est la
 * SEULE porte du site ouverte sans jeton de session ni jeton CSRF : elle n'est
 * tenue que par la signature cryptographique du corps.
 */
final class WebhookStripeTest extends FunctionalTestCase
{
    private const SECRET = 'whsec_test_secret';

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway(self::SECRET);

        // La vraie passerelle appellerait le reseau a la creation de session ;
        // la verification de signature, elle, est identique (WebhookSignature).
        $this->withService(PaymentGateway::class, fn (): PaymentGateway => $this->gateway);
        $this->withEnv(['STRIPE_WEBHOOK_SECRET' => self::SECRET]);
    }

    public function test_un_corps_correctement_signe_est_accepte(): void
    {
        $reponse = $this->envoyerSigne($this->corps('evt_1'));

        $this->assertSame(200, $reponse->status);
    }

    public function test_une_signature_invalide_est_refusee(): void
    {
        // 03-boutique §6.2 : « signature invalide -> 400, aucun effet,
        // evenement journalise ».
        $reponse = $this->envoyerMalSigne($this->corps('evt_1'));

        $this->assertSame(400, $reponse->status);
        $this->assertSame(0, $this->compter('stripe_events'), 'Rien ne doit être enregistré.');
    }

    public function test_une_signature_absente_est_refusee(): void
    {
        $reponse = $this->envoyerAvecEntete($this->corps('evt_1'), null);

        $this->assertSame(400, $reponse->status);
    }

    public function test_un_corps_modifie_apres_signature_est_refuse(): void
    {
        // La signature porte sur le CORPS BRUT : c'est tout l'intérêt de lire
        // php://input avant toute normalisation.
        $corps = $this->corps('evt_1');
        $entete = $this->gateway->signPayload($corps, time());

        $reponse = $this->envoyerAvecEntete(str_replace('evt_1', 'evt_pirate', $corps), $entete);

        $this->assertSame(400, $reponse->status);
    }

    public function test_une_signature_invalide_est_journalisee(): void
    {
        // 06-securite §10 : les signatures de webhook invalides sont des
        // evenements de securite.
        $this->envoyerMalSigne($this->corps('evt_1'));

        $this->assertNotSame([], $this->logger->entries);
    }

    public function test_la_route_est_exemptee_de_jeton_csrf(): void
    {
        // 06-securite §3 : seule exemption du site. Une requete sans jeton CSRF
        // ne doit pas etre rejetee en 419 — Stripe n'en a aucun a fournir.
        $reponse = $this->envoyerSigne($this->corps('evt_1'));

        $this->assertNotSame(419, $reponse->status);
        $this->assertNotSame(403, $reponse->status);
    }

    public function test_la_reponse_n_est_jamais_mise_en_cache(): void
    {
        $reponse = $this->envoyerSigne($this->corps('evt_1'));

        // Response::withHeader normalise les noms en minuscules.
        $this->assertStringContainsString('no-store', $reponse->headers['cache-control'] ?? '');
    }

    public function test_un_evenement_est_enregistre_puis_marque_traite(): void
    {
        $this->envoyerSigne($this->corps('evt_1'));

        $this->assertSame(1, $this->compter('stripe_events'));
        $this->assertNotNull(
            $this->valeur("SELECT processed_at FROM stripe_events WHERE event_id = 'evt_1'"),
        );
    }

    public function test_le_rejeu_du_meme_evenement_repond_200_sans_second_effet(): void
    {
        $this->envoyerSigne($this->corps('evt_1'));
        $reponse = $this->envoyerSigne($this->corps('evt_1'));

        $this->assertSame(200, $reponse->status);
        $this->assertSame(1, $this->compter('stripe_events'));
    }

    public function test_un_corps_vide_est_refuse(): void
    {
        $reponse = $this->envoyerAvecEntete('', 't=' . time() . ',v1=' . str_repeat('a', 64));

        $this->assertSame(400, $reponse->status);
    }

    public function test_la_reponse_ne_fuit_aucun_detail(): void
    {
        // 06-securite §10 : l'appelant n'est pas authentifie. Le corps de la
        // reponse ne doit lui apprendre ni la forme attendue, ni l'etat interne.
        $reponse = $this->envoyerMalSigne($this->corps('evt_1'));

        $this->assertStringNotContainsString('whsec', $reponse->body);
        $this->assertStringNotContainsString('Exception', $reponse->body);
        $this->assertStringNotContainsString('signature', strtolower($reponse->body));
    }

    public function test_une_requete_get_sur_le_webhook_n_est_pas_routee(): void
    {
        // Un webhook ne se consulte pas : seul POST existe.
        $reponse = $this->requete('GET', '/webhooks/stripe');

        $this->assertContains($reponse->status, [404, 405]);
    }

    // ------------------------------------------------------------- assistance

    /** Corps correctement signe avec le secret de test. */
    private function envoyerSigne(string $corps): Response
    {
        return $this->envoyerAvecEntete($corps, $this->gateway->signPayload($corps, time()));
    }

    /** En-tete impose — ou absent quand il vaut null. */
    private function envoyerAvecEntete(string $corps, ?string $entete): Response
    {
        return $this->requete(
            'POST',
            '/webhooks/stripe',
            server: $entete === null ? [] : ['HTTP_STRIPE_SIGNATURE' => $entete],
            body: $corps,
        );
    }

    /** Signature syntaxiquement correcte mais fausse. */
    private function envoyerMalSigne(string $corps): Response
    {
        return $this->envoyerAvecEntete($corps, 't=' . time() . ',v1=' . str_repeat('0', 64));
    }

    private function corps(string $eventId): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_inexistante',
                'client_reference_id' => 'CT-2026-9999',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_1',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function compter(string $table): int
    {
        return (int) $this->valeur("SELECT COUNT(*) FROM `{$table}`");
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return null;
        }

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
