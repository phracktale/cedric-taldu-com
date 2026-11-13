<?php

declare(strict_types=1);

namespace Tests\Integration;

use APress\Core\Extension\ExtensionState;
use APress\Core\Repository\PdoExtensionStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\DatabaseTestCase;

#[CoversClass(PdoExtensionStateStore::class)]
final class ExtensionStateStoreTest extends DatabaseTestCase
{
    public function test_un_etat_enregistre_est_relu_a_l_identique(): void
    {
        $store = new PdoExtensionStateStore($this->pdo);

        $store->save(new ExtensionState('agenda', true, '1.0.0', '2026-11-13 10:00:00'));

        $etat = $store->all()['agenda'];
        $this->assertTrue($etat->active);
        $this->assertSame('1.0.0', $etat->version);
        $this->assertSame('2026-11-13 10:00:00', $etat->changedAt);
    }

    public function test_enregistrer_a_nouveau_met_a_jour_sans_dupliquer(): void
    {
        $store = new PdoExtensionStateStore($this->pdo);
        $store->save(new ExtensionState('agenda', true, '1.0.0', '2026-11-13 10:00:00'));

        $store->save(new ExtensionState('agenda', false, '1.0.0', '2026-11-13 11:00:00'));

        $this->assertCount(1, $store->all());
        $this->assertFalse($store->all()['agenda']->active);
    }

    public function test_les_etats_sont_rendus_par_nom(): void
    {
        $store = new PdoExtensionStateStore($this->pdo);
        $store->save(new ExtensionState('rappels', true, '0.3.0', '2026-11-13 10:00:00'));
        $store->save(new ExtensionState('agenda', true, '1.0.0', '2026-11-13 10:00:00'));

        $this->assertSame(['agenda', 'rappels'], array_keys($store->all()));
    }
}
