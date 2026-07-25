<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use App\Core\Response;
use App\Service\Mail\ArrayMailer;
use App\Service\Mail\MailerInterface;
use App\Service\Payment\FakeGateway;
use App\Service\Payment\PaymentGateway;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Critère de fin du lot 5 (08-lots) : « le site entier est navigable en anglais,
 * y compris un achat complet ».
 *
 * On parcourt le tunnel en anglais, du panier au webhook : la commande naît en
 * `en`, la page de confirmation est servie en anglais, et l'e-mail au client
 * part dans sa langue (05-i18n-seo §4).
 */
final class AchatAnglaisTest extends FunctionalTestCase
{
    private const COOKIE = 'ct_cart';
    private const SECRET = 'whsec_test';

    private FakeGateway $gateway;
    private ArrayMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway(self::SECRET);
        $this->mailer = new ArrayMailer();
        $this->withService(PaymentGateway::class, fn (): PaymentGateway => $this->gateway);
        $this->withService(MailerInterface::class, fn (): MailerInterface => $this->mailer);
        $this->withEnv(['STRIPE_WEBHOOK_SECRET' => self::SECRET]);
    }

    public function test_le_panier_anglais_s_affiche_en_anglais(): void
    {
        $reponse = $this->get('/cedric-taldu/en/cart');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Your cart', $reponse->body);
        $this->assertStringContainsString('lang="en"', $reponse->body);
    }

    public function test_un_achat_complet_en_anglais_aboutit(): void
    {
        $categorie = (new CategoryFactory($this->pdo))->published()
            ->translated('en', 'inks', 'Inks')->translated('fr', 'encres', 'Encres')->create();
        $artwork = (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('en', 'articulation', 'Articulation')
            ->translated('fr', 'articulation-fr', 'Articulation')
            ->create($categorie);

        $cookie = $this->ajouter($artwork);
        $this->commander($cookie);

        // La commande naît dans la langue du tunnel.
        $this->assertSame('en', $this->valeur('SELECT locale FROM orders'));
        $reference = (string) $this->valeur('SELECT reference FROM orders');

        $this->webhook($reference);

        $this->assertSame('paid', $this->valeur('SELECT status FROM orders'));
        $this->assertSame('sold', $this->valeur("SELECT status FROM artworks WHERE id = {$artwork}"));

        // L'acheteur est notifié dans SA langue (sujet anglais).
        $email = $this->mailer->lastTo('buyer@example.test');
        $this->assertNotNull($email);
        $this->assertStringContainsString('Your order', $email->subject);

        // La page de confirmation est servie en anglais.
        $token = (string) $this->valeur('SELECT access_token FROM orders');
        $confirmation = $this->get('/cedric-taldu/en/checkout/confirmation/' . $reference . '?t=' . $token);
        $this->assertSame(200, $confirmation->status);
        $this->assertStringContainsString('lang="en"', $confirmation->body);
    }

    // ------------------------------------------------------------ assistance

    private function ajouter(int $artworkId): string
    {
        $reponse = $this->requete('POST', '/cedric-taldu/en/cart/add', post: [
            'kind' => 'original',
            'id' => (string) $artworkId,
            Csrf::FIELD => $this->jeton(),
        ]);

        foreach ($reponse->cookies as $cookie) {
            if ($cookie->name === self::COOKIE) {
                return $cookie->value;
            }
        }

        return '';
    }

    private function commander(string $cookie): Response
    {
        return $this->requete('POST', '/cedric-taldu/en/checkout', cookies: [self::COOKIE => $cookie], post: [
            'nom' => 'Buyer',
            'email' => 'buyer@example.test',
            'mode' => 'pickup',
            'cgv' => 'on',
            Csrf::FIELD => $this->jeton(),
        ]);
    }

    private function webhook(string $reference): Response
    {
        $corps = json_encode([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test',
                'client_reference_id' => $reference,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_1',
            ]],
        ], JSON_THROW_ON_ERROR);

        return $this->requete('POST', '/cedric-taldu/webhooks/stripe', server: [
            'HTTP_STRIPE_SIGNATURE' => $this->gateway->signPayload($corps, time()),
        ], body: $corps);
    }

    private function jeton(): string
    {
        $jeton = $this->session->get(Csrf::SESSION_KEY);

        if (!is_string($jeton) || $jeton === '') {
            $jeton = str_repeat('a', 64);
            $this->session->set(Csrf::SESSION_KEY, $jeton);
        }

        return $jeton;
    }

    private function valeur(string $sql): string|int|null
    {
        $value = $this->pdo->query($sql)->fetchColumn();

        return $value === false ? null : $value;
    }
}
