<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shop;

use App\Domain\Shop\ManagedReproductions;
use PHPUnit\Framework\TestCase;

/**
 * Catalogue des tirages gérés (SKU Prodigi proposés à l'ajout automatique).
 */
final class ManagedReproductionsTest extends TestCase
{
    public function test_chaque_taille_porte_un_sku_un_champ_une_taille_et_un_poids(): void
    {
        $tailles = ManagedReproductions::all();

        $this->assertNotSame([], $tailles);

        foreach ($tailles as $taille) {
            $this->assertArrayHasKey('sku', $taille);
            $this->assertArrayHasKey('field', $taille);
            $this->assertArrayHasKey('size', $taille);
            $this->assertGreaterThan(0, $taille['weight']);
        }
    }

    public function test_le_champ_de_prix_ne_contient_aucun_caractere_special(): void
    {
        // Le nom du champ finit dans un formulaire : les tirets du SKU y
        // deviendraient des clés PHP fantaisistes.
        $this->assertSame('prix_GLOBAL_HGE_16X20', ManagedReproductions::field('GLOBAL-HGE-16X20'));
    }

    public function test_le_sku_boutique_est_prefixe_par_l_oeuvre(): void
    {
        // Le même SKU Prodigi sert plusieurs œuvres ; le SKU boutique, lui, est
        // globalement unique.
        $this->assertSame('CT7-HGE-16X20', ManagedReproductions::shopSku(7, 'GLOBAL-HGE-16X20'));
        $this->assertNotSame(
            ManagedReproductions::shopSku(7, 'GLOBAL-HGE-16X20'),
            ManagedReproductions::shopSku(8, 'GLOBAL-HGE-16X20'),
        );
    }
}
