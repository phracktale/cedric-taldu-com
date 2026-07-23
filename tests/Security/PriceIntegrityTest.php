<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Csrf;
use App\Service\Payment\FakeGateway;
use App\Service\Payment\PaymentGateway;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §7 et 03-boutique §8 : le client ne transmet jamais de prix, de
 * montant, de frais de port ou de total. Seuls des identifiants et des
 * quantites transitent, et le total envoye a Stripe est RECALCULE en base.
 *
 * Ce test attaque le tunnel avec des montants forges et verifie qu'aucun
 * n'atteint la commande ni la session de paiement.
 */
final class PriceIntegrityTest extends FunctionalTestCase
{
    private const COOKIE = 'ct_cart';

    private FakeGateway $gateway;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway('whsec_test');
        $this->withService(PaymentGateway::class, fn (): PaymentGateway => $this->gateway);
        $this->withEnv(['STRIPE_WEBHOOK_SECRET' => 'whsec_test']);

        $this->categoryId = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->create();
    }

    public function test_un_prix_force_a_l_ajout_au_panier_est_ignore(): void
    {
        $artwork = $this->oeuvre(45000);

        $ajout = $this->requete('POST', '/cedric-taldu/fr/panier/ajout', post: [
            'kind' => 'original',
            'id' => (string) $artwork,
            // Champs pieges : le controleur ne doit lire ni l'un ni l'autre.
            'price' => '1',
            'price_cents' => '1',
            'unit_price_cents' => '1',
            'total' => '1',
            Csrf::FIELD => $this->jeton(),
        ]);

        $cookie = $this->cookieDe($ajout);
        $panier = $this->requete('GET', '/cedric-taldu/fr/panier', cookies: [self::COOKIE => $cookie]);

        $this->assertStringContainsString('450,00', $panier->body);
        $this->assertStringNotContainsString('0,01', $panier->body);
    }

    public function test_un_total_force_a_la_commande_n_atteint_pas_stripe(): void
    {
        $artwork = $this->oeuvre(45000);
        $cookie = $this->ajouter('original', $artwork);

        $this->requete('POST', '/cedric-taldu/fr/commande', cookies: [self::COOKIE => $cookie], post: [
            'nom' => 'Acheteur',
            'email' => 'acheteur@example.test',
            'mode' => 'pickup',
            'cgv' => 'on',
            // Toute la panoplie du fraudeur au prix.
            'total' => '1',
            'total_cents' => '1',
            'subtotal_cents' => '1',
            'shipping_cents' => '0',
            'amount' => '1',
            Csrf::FIELD => $this->jeton(),
        ]);

        // La commande porte le prix de la base, pas celui du formulaire.
        $this->assertSame(45000, (int) $this->valeur('SELECT total_cents FROM orders'));
        $this->assertSame(45000, (int) $this->valeur('SELECT subtotal_cents FROM orders'));

        // Et la session Stripe a recu ce meme montant recalcule.
        $demande = $this->gateway->lastCheckout();
        $this->assertNotNull($demande);
        $this->assertSame(45000, $demande['total']);
    }

    public function test_le_stock_client_ne_fixe_pas_la_quantite_facturee(): void
    {
        // Une quantite au-dela de la borne est ramenee, pas facturee telle
        // quelle : un « 99 » sur un original reste 1 (piece unique).
        $artwork = $this->oeuvre(45000);

        $this->requete('POST', '/cedric-taldu/fr/panier/ajout', post: [
            'kind' => 'original',
            'id' => (string) $artwork,
            'quantite' => '99',
            Csrf::FIELD => $this->jeton(),
        ]);

        $this->assertSame(1, (int) $this->valeur('SELECT qty FROM cart_items'));
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
        return $this->cookieDe($this->requete('POST', '/cedric-taldu/fr/panier/ajout', post: [
            'kind' => $kind,
            'id' => (string) $id,
            Csrf::FIELD => $this->jeton(),
        ]));
    }

    private function cookieDe(\App\Core\Response $reponse): string
    {
        foreach ($reponse->cookies as $cookie) {
            if ($cookie->name === self::COOKIE) {
                return $cookie->value;
            }
        }

        return '';
    }

    private function oeuvre(int $prix): int
    {
        $id = (new ArtworkFactory($this->pdo))->published()->available()->priced($prix)
            ->translated('fr', 'articulation', 'Articulation')
            ->create($this->categoryId);

        return $id;
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
