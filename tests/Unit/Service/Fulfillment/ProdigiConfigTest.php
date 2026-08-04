<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Fulfillment;

use App\Service\Fulfillment\ProdigiConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sélection de la clé Prodigi par PRODIGI_ENV, sur le modèle de StripeConfig.
 *
 * Le .env porte les deux clés (sandbox et live) ; PRODIGI_ENV désigne l'active.
 * Invariant repris de Stripe : la clé LIVE (impressions réellement facturées à
 * l'artiste) n'est active qu'en production. PRODIGI_ENV=live hors prod passerait
 * de vraies commandes chez Prodigi pendant une recette — c'est refusé.
 */
#[CoversClass(ProdigiConfig::class)]
final class ProdigiConfigTest extends TestCase
{
    /** @return array{sandboxKey: string, liveKey: string} */
    private function keys(): array
    {
        return ['sandboxKey' => 'sk-sandbox-abc', 'liveKey' => 'sk-live-xyz'];
    }

    public function test_le_mode_sandbox_selectionne_la_cle_sandbox_et_son_url(): void
    {
        $config = ProdigiConfig::resolve('sandbox', 'preprod', $this->keys());

        $this->assertSame('sk-sandbox-abc', $config->apiKey);
        $this->assertSame('https://api.sandbox.prodigi.com', $config->baseUrl);
        $this->assertTrue($config->isConfigured());
    }

    public function test_le_mode_live_en_production_selectionne_la_cle_live_et_son_url(): void
    {
        $config = ProdigiConfig::resolve('live', 'prod', $this->keys());

        $this->assertSame('sk-live-xyz', $config->apiKey);
        $this->assertSame('https://api.prodigi.com', $config->baseUrl);
    }

    public function test_prodigi_env_vide_vaut_sandbox_par_defaut(): void
    {
        // Par omission, jamais la production : le défaut est sûr.
        $config = ProdigiConfig::resolve('', 'preprod', $this->keys());

        $this->assertSame('sk-sandbox-abc', $config->apiKey);
        $this->assertSame(ProdigiConfig::MODE_SANDBOX, $config->mode);
    }

    public function test_le_mode_live_hors_production_est_refuse(): void
    {
        // Le cas le plus coûteux : passer de vraies commandes Prodigi en recette.
        $this->expectException(RuntimeException::class);

        ProdigiConfig::resolve('live', 'preprod', $this->keys());
    }

    public function test_le_mode_sandbox_en_production_est_autorise(): void
    {
        // Recette en production avant la bascule : le sandbox y reste admis.
        $config = ProdigiConfig::resolve('sandbox', 'prod', $this->keys());

        $this->assertSame('sk-sandbox-abc', $config->apiKey);
    }

    public function test_une_valeur_de_prodigi_env_inconnue_est_refusee(): void
    {
        $this->expectException(RuntimeException::class);

        ProdigiConfig::resolve('prod', 'preprod', $this->keys());
    }

    public function test_une_cle_active_vide_signifie_fulfillment_non_configure(): void
    {
        // Clé absente = « pas encore branché » : le site démarre partout, et
        // c'est la tentative de soumission qui échouera proprement.
        $config = ProdigiConfig::resolve('sandbox', 'preprod', ['sandboxKey' => '', 'liveKey' => '']);

        $this->assertSame('', $config->apiKey);
        $this->assertFalse($config->isConfigured());
    }

    public function test_le_mode_live_en_production_sans_cle_laisse_demarrer(): void
    {
        $config = ProdigiConfig::resolve('live', 'prod', ['sandboxKey' => 'sk-sandbox-abc', 'liveKey' => '']);

        $this->assertFalse($config->isConfigured());
    }
}
