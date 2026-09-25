<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Fulfillment;

use App\Service\Fulfillment\ProdigiOrderState;
use PHPUnit\Framework\TestCase;

/**
 * Lecture d'une commande Prodigi (webhook ou GET /orders/{id}) : l'étape et la
 * première expédition suivie — transporteur, numéro, lien de suivi.
 */
final class ProdigiOrderStateTest extends TestCase
{
    public function test_etape_et_premiere_expedition_suivie(): void
    {
        $etat = ProdigiOrderState::fromOrder([
            'id' => 'ord_1',
            'status' => ['stage' => 'Complete'],
            'shipments' => [
                ['carrier' => ['name' => 'Royal Mail'], 'tracking' => ['number' => '', 'url' => null]],
                ['carrier' => ['name' => 'DHL'], 'tracking' => ['number' => 'JD0001', 'url' => 'https://www.dhl.com/track?id=JD0001']],
            ],
        ]);

        $this->assertSame('Complete', $etat->stage);
        $this->assertSame('DHL', $etat->carrier);
        $this->assertSame('JD0001', $etat->trackingNumber);
        $this->assertSame('https://www.dhl.com/track?id=JD0001', $etat->trackingUrl);
        $this->assertTrue($etat->isShipped());
    }

    public function test_sans_suivi_la_commande_n_est_pas_expediee(): void
    {
        $etat = ProdigiOrderState::fromOrder(['status' => ['stage' => 'InProgress'], 'shipments' => []]);

        $this->assertSame('InProgress', $etat->stage);
        $this->assertFalse($etat->isShipped());
        $this->assertNull($etat->trackingUrl);
    }

    public function test_un_lien_de_suivi_non_https_est_ecarte(): void
    {
        $etat = ProdigiOrderState::fromOrder([
            'status' => ['stage' => 'Complete'],
            'shipments' => [['carrier' => ['name' => 'X'], 'tracking' => ['number' => 'N1', 'url' => 'javascript:alert(1)']]],
        ]);

        $this->assertSame('N1', $etat->trackingNumber);
        $this->assertNull($etat->trackingUrl);
    }

    public function test_une_etape_absente_devient_unknown(): void
    {
        $this->assertSame('Unknown', ProdigiOrderState::fromOrder([])->stage);
    }
}
