<?php

declare(strict_types=1);

namespace Tests\Unit\Service\View;

use App\Service\View\AdminMenu;
use PHPUnit\Framework\TestCase;

/**
 * Menu du back-office regroupé en rubriques (retours du 2026-09-25).
 */
final class AdminMenuTest extends TestCase
{
    public function test_les_rubriques_et_leurs_entrees_suivent_l_ordre_demande(): void
    {
        $groupes = AdminMenu::groups();

        $this->assertSame(['Contenus', 'Boutique', 'Modules', 'Paramètres'], array_column($groupes, 'label'));
        $this->assertSame(['Médiathèque', 'Accueil', 'Pages', 'Actus', 'Blocs'], $this->libelles($groupes[0]));
        $this->assertSame(['Galeries', 'Œuvres', null, 'Facturation', 'Commandes', 'Livraisons'], $this->libelles($groupes[1]));
        $this->assertSame(['Messages', 'Newsletter'], array_slice($this->libelles($groupes[2]), 0, 2));
        $this->assertSame(['Apparence', 'Menu'], array_values(array_intersect($this->libelles($groupes[3]), ['Apparence', 'Menu'])));
    }

    public function test_la_rubrique_de_la_page_courante_est_reperee(): void
    {
        $this->assertSame('Boutique', AdminMenu::groupOf('/admin/commandes/12'));
        $this->assertSame('Contenus', AdminMenu::groupOf('/admin/pages'));
        $this->assertNull(AdminMenu::groupOf('/admin'));
    }

    /**
     * @param array{label: string, items: list<array{chemin: string, libelle: string}|null>} $groupe
     * @return list<string|null>
     */
    private function libelles(array $groupe): array
    {
        return array_map(static fn (?array $item): ?string => $item['libelle'] ?? null, $groupe['items']);
    }
}
