<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Order\SellerIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Identité du vendeur sur les factures (revue du 2026-09-24) : réglable, pour
 * qu'un autre artiste reprenne le produit sans toucher au code.
 */
final class SellerIdentityTest extends TestCase
{
    public function test_un_reglage_complet_est_relu(): void
    {
        $vendeur = SellerIdentity::fromSetting([
            'name' => 'Cédric Taldu',
            'address' => "25 allée des Lilas\n80470 Dreuil-lès-Amiens\nFrance",
            'siret' => '495 376 436 00046',
            'email' => 'contact@cedrictaldu.com',
            'extra' => 'Maison des artistes n° T174380',
        ]);

        $this->assertSame('Cédric Taldu', $vendeur->name);
        $this->assertSame(['25 allée des Lilas', '80470 Dreuil-lès-Amiens', 'France'], $vendeur->addressLines());
        $this->assertSame('495 376 436 00046', $vendeur->siret);
        $this->assertSame('Maison des artistes n° T174380', $vendeur->extra);
    }

    public function test_sans_reglage_les_champs_sont_vides_mais_la_facture_reste_possible(): void
    {
        $vendeur = SellerIdentity::fromSetting([], 'Cédric Taldu', 'contact@cedrictaldu.com');

        $this->assertSame('Cédric Taldu', $vendeur->name);
        $this->assertSame('contact@cedrictaldu.com', $vendeur->email);
        $this->assertSame([], $vendeur->addressLines());
        $this->assertFalse($vendeur->isComplete());
    }

    public function test_les_valeurs_sont_nettoyees_et_bornees(): void
    {
        $vendeur = SellerIdentity::fromSetting(['name' => "  Atelier\x00 ", 'siret' => str_repeat('9', 80)]);

        $this->assertSame('Atelier', $vendeur->name);
        $this->assertSame(40, mb_strlen($vendeur->siret));
    }
}
