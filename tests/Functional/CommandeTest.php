<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use App\Core\Response;
use App\Service\Payment\FakeGateway;
use App\Service\Payment\PaymentGateway;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Tunnel de commande (03-boutique §3).
 *
 * Le formulaire recueille l'identite, le mode de remise et l'adresse ; a la
 * validation, le panier est revalide, les montants recalcules, la commande
 * creee en `pending` et le client redirige vers Stripe. Aucun prix ne transite
 * par le formulaire.
 */
final class CommandeTest extends FunctionalTestCase
{
    private const COOKIE = 'ct_cart';

    private int $categoryId;
    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway('whsec_test');
        $this->withService(PaymentGateway::class, fn (): PaymentGateway => $this->gateway);
        $this->withEnv(['STRIPE_WEBHOOK_SECRET' => 'whsec_test']);

        $this->categoryId = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->create();
    }

    // ----------------------------------------------------------- affichage

    public function test_le_formulaire_de_commande_s_affiche_avec_un_panier(): void
    {
        $cookie = $this->panierAvecOeuvre();

        $reponse = $this->requete('GET', '/cedric-taldu/fr/commande', cookies: [self::COOKIE => $cookie]);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="email"', $reponse->body);
        // Case CGV obligatoire (03-boutique §3, étape 2).
        $this->assertStringContainsString('name="cgv"', $reponse->body);
    }

    public function test_le_tunnel_affiche_le_port_le_total_la_reception_et_le_paiement(): void
    {
        // 03-boutique §3 : le récapitulatif éclaire l'acheteur avant paiement —
        // frais de port, total, fenêtre de réception estimée, moyen de paiement.
        $cookie = $this->panierAvecOeuvre();

        $corps = $this->requete('GET', '/cedric-taldu/fr/commande', cookies: [self::COOKIE => $cookie])->body;

        $this->assertStringContainsString('Frais de port', $corps);
        $this->assertStringContainsString('Réception estimée entre le', $corps);
        $this->assertStringContainsString('Paiement sécurisé par carte', $corps);
        // Les deux modes portent leur total, pour la mise à jour côté client.
        $this->assertStringContainsString('value="shipping"', $corps);
        $this->assertStringContainsString('value="pickup"', $corps);
        $this->assertStringContainsString('data-total=', $corps);
    }

    public function test_la_case_cgv_pointe_le_pdf_dans_la_langue_du_tunnel(): void
    {
        // La case doit permettre de LIRE les CGV avant de les accepter : un lien
        // vers le PDF, dans la langue courante.
        $cookie = $this->panierAvecOeuvre();

        $fr = $this->requete('GET', '/cedric-taldu/fr/commande', cookies: [self::COOKIE => $cookie])->body;
        $en = $this->requete('GET', '/cedric-taldu/en/checkout', cookies: [self::COOKIE => $cookie])->body;

        $this->assertStringContainsString('documents/cgv-cedric-taldu-fr.pdf', $fr);
        $this->assertStringContainsString('documents/cgv-cedric-taldu-en.pdf', $en);
    }

    public function test_un_panier_vide_renvoie_au_panier(): void
    {
        $reponse = $this->requete('GET', '/cedric-taldu/fr/commande');

        $this->assertSame(303, $reponse->status);
        $this->assertStringContainsString('/panier', $reponse->header('Location') ?? '');
    }

    // ----------------------------------------------------------- validation

    public function test_une_commande_valide_redirige_vers_stripe(): void
    {
        $cookie = $this->panierAvecOeuvre();

        $reponse = $this->commander($cookie);

        $this->assertSame(303, $reponse->status);
        $this->assertStringContainsString('checkout.stripe.test', $reponse->header('Location') ?? '');
        $this->assertSame(1, (int) $this->valeur('SELECT COUNT(*) FROM orders'));
        $this->assertSame('pending', $this->valeur('SELECT status FROM orders'));
    }

    public function test_la_commande_fige_les_bons_montants(): void
    {
        // 60 € de tirage, sous le franco : port France 9 €.
        $cookie = $this->panierAvecReproduction();

        $this->commander($cookie);

        $this->assertSame(6000, (int) $this->valeur('SELECT subtotal_cents FROM orders'));
        $this->assertSame(900, (int) $this->valeur('SELECT shipping_cents FROM orders'));
        $this->assertSame(6900, (int) $this->valeur('SELECT total_cents FROM orders'));
    }

    public function test_l_oeuvre_est_reservee_par_la_commande(): void
    {
        $cookie = $this->panierAvecOeuvre();

        $this->commander($cookie);

        $this->assertSame('reserved', $this->valeur('SELECT status FROM artworks LIMIT 1'));
    }

    public function test_le_montant_envoye_a_stripe_est_celui_recalcule(): void
    {
        // 03-boutique §8.2 : PriceIntegrity. Le formulaire pousse un faux total,
        // ignore.
        $cookie = $this->panierAvecOeuvre();

        $this->commander($cookie, ['total' => '1', 'prix' => '1']);

        $demande = $this->gateway->lastCheckout();
        $this->assertNotNull($demande);
        $this->assertSame(45000, $demande['total']);
    }

    public function test_les_conditions_generales_non_acceptees_bloquent_la_commande(): void
    {
        $cookie = $this->panierAvecOeuvre();

        $reponse = $this->commander($cookie, ['cgv' => null]);

        $this->assertContains($reponse->status, [422, 200]);
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM orders'));
    }

    public function test_une_adresse_incomplete_en_expedition_bloque_la_commande(): void
    {
        $cookie = $this->panierAvecOeuvre();

        $reponse = $this->commander($cookie, ['ville' => '', 'code_postal' => '']);

        $this->assertContains($reponse->status, [422, 200]);
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM orders'));
    }

    public function test_une_remise_en_main_propre_n_exige_pas_d_adresse(): void
    {
        $cookie = $this->panierAvecOeuvre();

        $reponse = $this->commander($cookie, [
            'mode' => 'pickup',
            'ville' => '',
            'code_postal' => '',
            'adresse' => '',
        ]);

        $this->assertSame(303, $reponse->status);
        $this->assertSame('pickup', $this->valeur('SELECT shipping_method FROM orders'));
        $this->assertSame(0, (int) $this->valeur('SELECT shipping_cents FROM orders'));
    }

    public function test_un_honeypot_rempli_est_rejete_sans_creer_de_commande(): void
    {
        // 06-securite §6.1 : champ masqué rempli → rejet, sans informer le robot.
        $cookie = $this->panierAvecOeuvre();

        $reponse = $this->commander($cookie, ['site_web' => 'http://spam.example']);

        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM orders'));
        // Réponse d'apparence normale : ni 500, ni message d'erreur explicite.
        $this->assertNotSame(500, $reponse->status);
    }

    public function test_une_oeuvre_vendue_entre_temps_renvoie_au_panier(): void
    {
        $cookie = $this->panierAvecOeuvre();
        $this->pdo->exec("UPDATE artworks SET status = 'sold'");

        $reponse = $this->commander($cookie);

        $this->assertSame(303, $reponse->status);
        $this->assertStringContainsString('/panier', $reponse->header('Location') ?? '');
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM orders'));
    }

    public function test_un_email_avec_saut_de_ligne_est_refuse(): void
    {
        // 06-securite §6.6 : CRLF dans l'e-mail → rejet.
        $cookie = $this->panierAvecOeuvre();

        $reponse = $this->commander($cookie, ['email' => "acheteur@example.test\r\nBcc: x@y.z"]);

        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM orders'));
        $this->assertNotSame(303, $reponse->status);
    }

    public function test_un_ajout_de_commande_sans_jeton_csrf_est_refuse(): void
    {
        $cookie = $this->panierAvecOeuvre();

        $reponse = $this->requete('POST', '/cedric-taldu/fr/commande', cookies: [self::COOKIE => $cookie], post: [
            'email' => 'acheteur@example.test',
        ]);

        $this->assertContains($reponse->status, [403, 419]);
    }

    // --------------------------------------------------------- confirmation

    public function test_la_page_de_confirmation_lit_l_etat_de_la_commande(): void
    {
        $cookie = $this->panierAvecOeuvre();
        $this->commander($cookie);

        $reference = (string) $this->valeur('SELECT reference FROM orders');
        $token = (string) $this->valeur('SELECT access_token FROM orders');

        $reponse = $this->requete(
            'GET',
            '/cedric-taldu/fr/commande/confirmation/' . $reference . '?t=' . $token,
        );

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString($reference, $reponse->body);
    }

    public function test_la_confirmation_sans_jeton_valide_ne_montre_rien(): void
    {
        // 03-boutique §3, étape 3 : « aucune information de commande n'est
        // accessible sans lui ».
        $cookie = $this->panierAvecOeuvre();
        $this->commander($cookie);

        $reference = (string) $this->valeur('SELECT reference FROM orders');

        $reponse = $this->requete(
            'GET',
            '/cedric-taldu/fr/commande/confirmation/' . $reference . '?t=' . str_repeat('0', 64),
        );

        $this->assertSame(404, $reponse->status);
    }

    public function test_la_confirmation_d_une_reference_inconnue_repond_404(): void
    {
        $reponse = $this->requete(
            'GET',
            '/cedric-taldu/fr/commande/confirmation/CT-2026-9999?t=' . str_repeat('a', 64),
        );

        $this->assertSame(404, $reponse->status);
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

    /**
     * @param array<string, string|null> $surcharge
     */
    private function commander(string $cookie, array $surcharge = []): Response
    {
        $post = [
            'nom' => 'Acheteur',
            'email' => 'acheteur@example.test',
            'mode' => 'shipping',
            'adresse' => '12 rue des Trois-Cailloux',
            'code_postal' => '80000',
            'ville' => 'Amiens',
            'pays' => 'FR',
            'cgv' => 'on',
            Csrf::FIELD => $this->jeton(),
            ...$surcharge,
        ];

        $post = array_filter($post, static fn ($v): bool => $v !== null);

        return $this->requete('POST', '/cedric-taldu/fr/commande', cookies: [self::COOKIE => $cookie], post: $post);
    }

    private function panierAvecOeuvre(): string
    {
        $artwork = (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')
            ->create($this->categoryId);

        return $this->ajouter('original', $artwork);
    }

    private function panierAvecReproduction(): string
    {
        $artwork = (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('fr', 'tirage', 'Œuvre')
            ->create($this->categoryId);

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
             VALUES (:prod, :sku, :size, 6000, 5, 300, NOW(), NOW())'
        )->execute(['prod' => $product, 'sku' => 'ART-' . bin2hex(random_bytes(4)), 'size' => '30 × 40 cm']);

        return $this->ajouter('reproduction', (int) $this->pdo->lastInsertId());
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
