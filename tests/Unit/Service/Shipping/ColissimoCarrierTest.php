<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Shipping;

use App\Service\Shipping\CarrierRegistry;
use App\Service\Shipping\ColissimoCarrier;
use PHPUnit\Framework\TestCase;

/**
 * Transporteur Colissimo (revue du 2026-09-24) : retenu pour la démonstration,
 * d'autres transporteurs se brancheront derrière la même interface.
 */
final class ColissimoCarrierTest extends TestCase
{
    public function test_le_lien_de_suivi_mene_a_la_poste(): void
    {
        $colissimo = new ColissimoCarrier('', '');

        $this->assertSame('colissimo', $colissimo->code());
        $this->assertSame('Colissimo', $colissimo->name());
        $this->assertSame(
            'https://www.laposte.fr/outils/suivre-vos-envois?code=6A123456789',
            $colissimo->trackingUrl('6A123456789'),
        );
    }

    public function test_le_numero_de_suivi_est_encode_dans_le_lien(): void
    {
        $this->assertStringEndsWith('?code=6A%26x%3D1', (new ColissimoCarrier('', ''))->trackingUrl('6A&x=1'));
    }

    public function test_sans_contrat_l_api_est_desactivee(): void
    {
        // Pas de compte Colissimo pour l'instant : le transporteur fonctionne
        // (tarif à la grille, suivi), l'API (étiquettes) attend ses identifiants.
        $this->assertFalse((new ColissimoCarrier('', ''))->apiEnabled());
        $this->assertFalse((new ColissimoCarrier('123456', ''))->apiEnabled());
        $this->assertTrue((new ColissimoCarrier('123456', 'secret'))->apiEnabled());
    }

    public function test_le_registre_retrouve_un_transporteur_par_son_nom(): void
    {
        $registre = new CarrierRegistry([new ColissimoCarrier('', '')]);

        $this->assertSame('colissimo', $registre->byName(' COLISSIMO ')?->code());
        $this->assertNull($registre->byName('Chronopost'));
        $this->assertSame('colissimo', $registre->default()->code());
        $this->assertSame(['Colissimo'], $registre->names());
    }
}
