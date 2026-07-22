<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\View;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\Address;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\VatCategory;
use App\Domain\Order\VatMode;
use App\Repository\PersistedOrder;
use App\Repository\PersistedOrderLine;
use App\Domain\Shipping\ShippingMethod;
use App\Domain\Shop\LineKind;
use App\Service\Mail\ArrayMailer;
use App\Service\Mail\OrderMailer;
use PHPUnit\Framework\TestCase;

/**
 * E-mails de commande (03-boutique §7).
 *
 * Deux destinataires : le client, dans la langue de sa commande, et l'artiste.
 * Les deux sont rendus par des GABARITS de `templates/`, et non construits en
 * PHP — c'est ce qui les place sous la surveillance d'EscapingTest, qui exige
 * que toute valeur passe par un helper d'echappement.
 *
 * Ce test ne touche pas la base : il part d'une commande deja hydratee.
 */
final class OrderMailerTest extends TestCase
{
    private ArrayMailer $mailer;
    private OrderMailer $orderMailer;

    protected function setUp(): void
    {
        $racine = dirname(__DIR__, 2);

        $this->mailer = new ArrayMailer();
        $this->orderMailer = new OrderMailer(
            new View($racine . '/templates', self::url($racine)),
            $this->mailer,
            'artiste@example.test',
            'Cédric Taldu',
        );
    }

    public function test_le_client_recoit_un_recapitulatif(): void
    {
        $this->orderMailer->sendConfirmation($this->commande(), 'https://example.test/fr/commande/CT-2026-0001?t=x');

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertStringContainsString('CT-2026-0001', $message->subject);
        $this->assertStringContainsString('Articulation', $message->html);
        $this->assertStringContainsString('570,00', $message->html);
    }

    public function test_l_artiste_recoit_le_detail_de_la_commande(): void
    {
        $this->orderMailer->sendConfirmation($this->commande(), 'https://example.test/fr/commande/CT-2026-0001?t=x');

        $message = $this->mailer->lastTo('artiste@example.test');

        $this->assertNotNull($message);
        $this->assertStringContainsString('CT-2026-0001', $message->html);
        $this->assertStringContainsString('acheteur@example.test', $message->html);
        $this->assertStringContainsString('Amiens', $message->html);
    }

    public function test_deux_messages_partent_pour_une_commande(): void
    {
        $this->orderMailer->sendConfirmation($this->commande(), 'https://example.test/x');

        $this->assertCount(2, $this->mailer->sent);
    }

    public function test_le_lien_de_consultation_figure_dans_le_message_du_client(): void
    {
        // 03-boutique §7 : « lien de consultation signe ». Sans lui, le client
        // n'a aucun moyen de revoir sa commande.
        $this->orderMailer->sendConfirmation($this->commande(), 'https://example.test/fr/commande/CT-2026-0001?t=jeton');

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertStringContainsString('t=jeton', $message->html);
    }

    public function test_la_mention_293_b_figure_en_franchise(): void
    {
        // 03-boutique §5.1 : mention OBLIGATOIRE sur la commande, la facture et
        // le recapitulatif.
        $this->orderMailer->sendConfirmation($this->commande(), 'https://example.test/x');

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertStringContainsString('293 B', $message->html);
    }

    public function test_la_mention_293_b_disparait_en_regime_taxe(): void
    {
        $this->orderMailer->sendConfirmation(
            $this->commande(mode: VatMode::Taxed),
            'https://example.test/x',
        );

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertStringNotContainsString('293 B', $message->html);
    }

    public function test_le_delai_de_retractation_est_annonce(): void
    {
        // Decision du 2026-07-21 : 14 jours, retour aux frais du client. La
        // mention doit figurer dans l'e-mail de confirmation.
        $this->orderMailer->sendConfirmation($this->commande(), 'https://example.test/x');

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertStringContainsString('14 jours', $message->html);
    }

    public function test_le_message_du_client_est_dans_la_langue_de_la_commande(): void
    {
        $this->orderMailer->sendConfirmation($this->commande(locale: Locale::En), 'https://example.test/x');

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertStringContainsString('Thank you', $message->html);
    }

    public function test_un_nom_de_client_hostile_est_echappe(): void
    {
        // XSS par e-mail : un client de messagerie qui rend le HTML executerait
        // ce que le gabarit laisserait passer.
        $this->orderMailer->sendConfirmation(
            $this->commande(nom: '<script>alert(1)</script>'),
            'https://example.test/x',
        );

        $message = $this->mailer->lastTo('artiste@example.test');

        $this->assertNotNull($message);
        $this->assertStringNotContainsString('<script>', $message->html);
        $this->assertStringContainsString('&lt;script&gt;', $message->html);
    }

    public function test_un_libelle_d_article_hostile_est_echappe(): void
    {
        $this->orderMailer->sendConfirmation(
            $this->commande(libelle: '"><img src=x onerror=alert(1)>'),
            'https://example.test/x',
        );

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertStringNotContainsString('<img src=x', $message->html);
    }

    public function test_un_message_de_texte_brut_accompagne_le_html(): void
    {
        // Un e-mail sans partie texte part plus facilement en indesirable, et
        // reste illisible pour qui refuse le HTML.
        $this->orderMailer->sendConfirmation($this->commande(), 'https://example.test/x');

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertNotSame('', trim($message->text));
        $this->assertStringNotContainsString('<', $message->text);
    }

    public function test_l_expedition_previent_le_client_avec_son_suivi(): void
    {
        $this->orderMailer->sendShipped(
            $this->commande(statut: OrderStatus::Shipped, transporteur: 'Colissimo', suivi: '6A123456789'),
            'https://example.test/x',
        );

        $message = $this->mailer->lastTo('acheteur@example.test');

        $this->assertNotNull($message);
        $this->assertStringContainsString('Colissimo', $message->html);
        $this->assertStringContainsString('6A123456789', $message->html);
    }

    // ------------------------------------------------------------ assistance

    private static function url(string $racine): \App\Service\I18n\UrlGenerator
    {
        /** @var list<\App\Core\Route> $routes */
        $routes = require $racine . '/config/routes.php';

        return new \App\Service\I18n\UrlGenerator(
            new \App\Core\Router($routes),
            \App\Core\Config::fromEnv(\App\Core\Env::fromArray([
                'APP_ENV' => 'preprod',
                'APP_DEBUG' => '0',
                'APP_URL' => 'https://example.test',
                'APP_BASE_PATH' => '',
                'APP_DEFAULT_LOCALE' => 'fr',
                'APP_LOCALES' => 'fr,en',
                'TRUSTED_PROXIES' => '',
                'SECURITY_PEPPER' => str_repeat('a', 64),
            ])),
            '',
            $racine . '/public',
        );
    }

    private function commande(
        Locale $locale = Locale::Fr,
        VatMode $mode = VatMode::Exempt293b,
        string $nom = 'Acheteur',
        string $libelle = 'Articulation',
        OrderStatus $statut = OrderStatus::Paid,
        ?string $transporteur = null,
        ?string $suivi = null,
    ): PersistedOrder {
        return new PersistedOrder(
            id: 1,
            reference: 'CT-2026-0001',
            status: $statut,
            locale: $locale,
            customerName: $nom,
            customerEmail: 'acheteur@example.test',
            customerPhone: null,
            shippingMethod: ShippingMethod::Shipping,
            shippingAddress: new Address('12 rue des Trois-Cailloux', null, '80000', 'Amiens', 'FR'),
            billingAddress: null,
            subtotal: Money::fromCents(57000),
            shipping: Money::zero(),
            vat: Money::zero(),
            total: Money::fromCents(57000),
            vatMode: $mode,
            accessToken: str_repeat('a', 64),
            customerNote: null,
            anomalyNote: null,
            trackingCarrier: $transporteur,
            trackingNumber: $suivi,
            lines: [
                new PersistedOrderLine(
                    id: 1,
                    kind: LineKind::Original,
                    artworkId: 7,
                    variantId: null,
                    label: $libelle,
                    sku: null,
                    quantity: 1,
                    unitPrice: Money::fromCents(45000),
                    total: Money::fromCents(45000),
                    vatCategory: VatCategory::OriginalArtwork,
                    vatRateBps: 0,
                    excludingVat: Money::fromCents(45000),
                    vat: Money::zero(),
                    shippingShare: Money::zero(),
                    editionNumber: null,
                    anomaly: null,
                ),
                new PersistedOrderLine(
                    id: 2,
                    kind: LineKind::Reproduction,
                    artworkId: null,
                    variantId: 12,
                    label: 'Tirage — 30 × 40 cm',
                    sku: 'ART-3040',
                    quantity: 2,
                    unitPrice: Money::fromCents(6000),
                    total: Money::fromCents(12000),
                    vatCategory: VatCategory::StandardGoods,
                    vatRateBps: 0,
                    excludingVat: Money::fromCents(12000),
                    vat: Money::zero(),
                    shippingShare: Money::zero(),
                    editionNumber: 3,
                    anomaly: null,
                ),
            ],
        );
    }
}
