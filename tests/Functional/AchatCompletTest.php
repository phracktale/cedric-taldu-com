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
 * Critere de fin du lot 3 (08-lots) : un achat d'original ET un achat de
 * reproduction aboutissent en mode test Stripe, avec decrement de stock,
 * e-mails et impossibilite de double vente prouvee.
 *
 * Ce test parcourt TOUTE la chaine, du panier au webhook, sans reseau : la
 * passerelle est doublee mais la signature du webhook est verifiee pour de
 * vrai, et le courrier est capture par un double partage.
 */
final class AchatCompletTest extends FunctionalTestCase
{
    private const COOKIE = 'ct_cart';
    private const SECRET = 'whsec_test';

    private FakeGateway $gateway;
    private ArrayMailer $mailer;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway(self::SECRET);
        $this->mailer = new ArrayMailer();

        // Doubles PARTAGES entre les requetes : le socle reconstruit le
        // conteneur a chaque appel, mais ces fabriques rendent la meme
        // instance, ce qui permet d'inspecter la session Stripe et les
        // courriels apres coup.
        $this->withService(PaymentGateway::class, fn (): PaymentGateway => $this->gateway);
        $this->withService(MailerInterface::class, fn (): MailerInterface => $this->mailer);
        $this->withEnv(['STRIPE_WEBHOOK_SECRET' => self::SECRET]);

        $this->categoryId = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->create();
    }

    public function test_un_achat_d_original_aboutit_de_bout_en_bout(): void
    {
        $artwork = $this->oeuvre();
        $cookie = $this->ajouter('original', $artwork);

        $this->commander($cookie);
        $reference = (string) $this->valeur('SELECT reference FROM orders');

        $this->webhook($reference);

        $this->assertSame('paid', $this->valeur('SELECT status FROM orders'));
        $this->assertSame('sold', $this->valeur("SELECT status FROM artworks WHERE id = {$artwork}"));
        $this->assertCount(2, $this->mailer->sent, 'Un e-mail au client, un à l’artiste.');
        $this->assertNotNull($this->mailer->lastTo('acheteur@example.test'));
    }

    public function test_un_achat_de_reproduction_decremente_le_stock(): void
    {
        $variant = $this->reproduction(stock: 5);
        $cookie = $this->ajouter('reproduction', $variant);

        $this->commander($cookie);
        $this->webhook((string) $this->valeur('SELECT reference FROM orders'));

        $this->assertSame('paid', $this->valeur('SELECT status FROM orders'));
        $this->assertSame(
            4,
            (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$variant}"),
        );
    }

    public function test_le_lien_de_consultation_de_l_email_ouvre_la_confirmation(): void
    {
        $artwork = $this->oeuvre();
        $cookie = $this->ajouter('original', $artwork);
        $this->commander($cookie);
        $this->webhook((string) $this->valeur('SELECT reference FROM orders'));

        $message = $this->mailer->lastTo('acheteur@example.test');
        $this->assertNotNull($message);

        // Le lien signe mene a une page 200 qui affiche la commande payee.
        $this->assertSame(1, preg_match('#href="([^"]*/commande/confirmation/[^"]+)"#', $message->html, $m));
        $chemin = html_entity_decode($m[1]);
        $chemin = (string) preg_replace('#^https?://[^/]+#', '', $chemin);

        $reponse = $this->requete('GET', $chemin);
        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsStringIgnoringCase('merci', $reponse->body);
    }

    public function test_le_double_paiement_de_la_meme_oeuvre_est_impossible(): void
    {
        // 03-boutique §8.5 : deux acheteurs paient la meme piece. Le second
        // garde sa commande payee — il a paye — mais elle part en anomalie, et
        // l'œuvre n'est vendue qu'une fois.
        $artwork = $this->oeuvre();

        $premier = $this->ajouter('original', $artwork);
        $this->commander($premier);
        $referenceUn = (string) $this->valeur('SELECT reference FROM orders ORDER BY id DESC LIMIT 1');

        // Le second acheteur commande AVANT que le premier webhook n'arrive :
        // la reservation du premier a expire pour les besoins du test, donc on
        // force les deux commandes a coexister en liberant la reservation.
        $this->pdo->exec("UPDATE artworks SET status = 'available', reserved_until = NULL WHERE id = {$artwork}");

        $second = $this->ajouter('original', $artwork);
        $this->commander($second);
        $referenceDeux = (string) $this->valeur('SELECT reference FROM orders ORDER BY id DESC LIMIT 1');

        $this->webhook($referenceUn, 'evt_1', 'cs_un');
        $this->webhook($referenceDeux, 'evt_2', 'cs_deux');

        // Une seule vente.
        $this->assertSame('sold', $this->valeur("SELECT status FROM artworks WHERE id = {$artwork}"));
        // Les deux commandes sont payees...
        $this->assertSame(2, (int) $this->valeur("SELECT COUNT(*) FROM orders WHERE status = 'paid'"));
        // ... mais l'une porte une anomalie.
        $this->assertSame(
            1,
            (int) $this->valeur('SELECT COUNT(*) FROM orders WHERE anomaly_note IS NOT NULL'),
        );
    }

    public function test_le_rejeu_du_webhook_ne_double_aucun_effet(): void
    {
        $variant = $this->reproduction(stock: 5);
        $cookie = $this->ajouter('reproduction', $variant);
        $this->commander($cookie);
        $reference = (string) $this->valeur('SELECT reference FROM orders');

        $this->webhook($reference, 'evt_1');
        $this->webhook($reference, 'evt_1');

        $this->assertSame(
            4,
            (int) $this->valeur("SELECT stock_qty FROM product_variants WHERE id = {$variant}"),
            'Le stock ne doit etre decremente qu’une fois.',
        );
        $this->assertCount(2, $this->mailer->sent, 'Les e-mails ne partent qu’une fois.');
    }

    // ------------------------------------------------------------ assistance

    private function jeton(): string
    {
        $jeton = $this->session->get(Csrf::SESSION_KEY);

        if (!is_string($jeton) || $jeton === '') {
            $jeton = str_repeat('a', 64);
            $this->session->set(Csrf::SESSION_KEY, $jeton);
        }

        return $jeton;
    }

    private function ajouter(string $kind, int $id): string
    {
        $reponse = $this->requete('POST', '/cedric-taldu/fr/panier/ajout', post: [
            'kind' => $kind,
            'id' => (string) $id,
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
        return $this->requete('POST', '/cedric-taldu/fr/commande', cookies: [self::COOKIE => $cookie], post: [
            'nom' => 'Acheteur',
            'email' => 'acheteur@example.test',
            'mode' => 'pickup',
            'cgv' => 'on',
            Csrf::FIELD => $this->jeton(),
        ]);
    }

    private function webhook(string $reference, string $eventId = 'evt_1', string $sessionId = 'cs_test'): Response
    {
        $corps = json_encode([
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sessionId,
                'client_reference_id' => $reference,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_' . $eventId,
            ]],
        ], JSON_THROW_ON_ERROR);

        // La route du webhook n'est PAS localisee (03-boutique §6) : pas de /fr.
        return $this->requete('POST', '/cedric-taldu/webhooks/stripe', server: [
            'HTTP_STRIPE_SIGNATURE' => $this->gateway->signPayload($corps, time()),
        ], body: $corps);
    }

    private function oeuvre(int $prix = 45000): int
    {
        return (new ArtworkFactory($this->pdo))->published()->available()->priced($prix)
            ->translated('fr', 'articulation', 'Articulation')
            ->create($this->categoryId);
    }

    private function reproduction(int $stock): int
    {
        $artwork = $this->oeuvre();

        $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, is_published, created_at, updated_at)
             VALUES (:art, :kind, 1, NOW(), NOW())'
        )->execute(['art' => $artwork, 'kind' => 'standard']);
        $product = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :l, :t)'
        )->execute(['id' => $product, 'l' => 'fr', 't' => 'Tirage d’art']);

        $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           created_at, updated_at)
             VALUES (:prod, :sku, :size, 6000, :stock, 300, NOW(), NOW())'
        )->execute(['prod' => $product, 'sku' => 'ART-' . bin2hex(random_bytes(4)), 'size' => '30 × 40 cm', 'stock' => $stock]);

        return (int) $this->pdo->lastInsertId();
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
