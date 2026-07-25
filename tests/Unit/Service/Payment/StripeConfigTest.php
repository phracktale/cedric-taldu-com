<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Payment;

use App\Service\Payment\StripeConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sélection de la paire de clés Stripe par STRIPE_ENV (06-securite §7).
 *
 * Le .env porte les deux paires (test et production) ; STRIPE_ENV désigne
 * l'active. Invariant de sécurité conservé : les clés de PRODUCTION ne sont
 * actives qu'en production — STRIPE_ENV=prod hors prod encaisserait de vrais
 * paiements pendant une recette. Une clé de test reste utilisable partout, y
 * compris en production (recette avant bascule).
 */
#[CoversClass(StripeConfig::class)]
final class StripeConfigTest extends TestCase
{
    /** @return array{testKey:string,testWebhook:string,liveKey:string,liveWebhook:string} */
    private function keys(): array
    {
        return [
            'testKey' => 'sk_test_abc',
            'testWebhook' => 'whsec_test',
            'liveKey' => 'sk_live_xyz',
            'liveWebhook' => 'whsec_live',
        ];
    }

    public function test_mode_test_selectionne_la_paire_de_test(): void
    {
        $config = StripeConfig::resolve('test', 'preprod', $this->keys());

        $this->assertSame('sk_test_abc', $config->secretKey);
        $this->assertSame('whsec_test', $config->webhookSecret);
        $this->assertTrue($config->isConfigured());
    }

    public function test_mode_prod_en_production_selectionne_la_paire_live(): void
    {
        $config = StripeConfig::resolve('prod', 'prod', $this->keys());

        $this->assertSame('sk_live_xyz', $config->secretKey);
        $this->assertSame('whsec_live', $config->webhookSecret);
    }

    public function test_stripe_env_vide_vaut_test_par_defaut(): void
    {
        // Par omission, jamais la production : le défaut est sûr.
        $config = StripeConfig::resolve('', 'preprod', $this->keys());

        $this->assertSame('sk_test_abc', $config->secretKey);
    }

    public function test_mode_prod_hors_production_est_refuse(): void
    {
        // Le cas le plus coûteux : activer les clés live pendant une recette.
        $this->expectException(RuntimeException::class);

        StripeConfig::resolve('prod', 'preprod', $this->keys());
    }

    public function test_mode_test_en_production_est_autorise(): void
    {
        // Recette en production avant la bascule : les clés de test y sont admises.
        $config = StripeConfig::resolve('test', 'prod', $this->keys());

        $this->assertSame('sk_test_abc', $config->secretKey);
    }

    public function test_une_cle_dont_le_prefixe_ne_correspond_pas_au_mode_est_refusee(): void
    {
        // Une sk_live_ rangée dans la paire de test trahit une erreur de saisie.
        $this->expectException(RuntimeException::class);

        StripeConfig::resolve('test', 'preprod', [
            'testKey' => 'sk_live_egaree',
            'testWebhook' => 'whsec_test',
            'liveKey' => '',
            'liveWebhook' => '',
        ]);
    }

    public function test_une_valeur_de_stripe_env_inconnue_est_refusee(): void
    {
        $this->expectException(RuntimeException::class);

        StripeConfig::resolve('sandbox', 'preprod', $this->keys());
    }

    public function test_une_paire_active_vide_signifie_paiement_non_configure(): void
    {
        // Clé absente = « pas encore branché » : le site démarre partout, et
        // c'est la tentative de paiement qui échouera.
        $config = StripeConfig::resolve('test', 'preprod', [
            'testKey' => '',
            'testWebhook' => '',
            'liveKey' => '',
            'liveWebhook' => '',
        ]);

        $this->assertSame('', $config->secretKey);
        $this->assertFalse($config->isConfigured());
    }

    public function test_mode_prod_en_production_sans_cle_live_laisse_demarrer(): void
    {
        // En prod, tant que la clé live n'est pas posée, le site démarre : le
        // paiement est simplement « non configuré », pas une faute bloquante.
        $config = StripeConfig::resolve('prod', 'prod', [
            'testKey' => 'sk_test_abc',
            'testWebhook' => 'whsec_test',
            'liveKey' => '',
            'liveWebhook' => '',
        ]);

        $this->assertFalse($config->isConfigured());
    }
}
