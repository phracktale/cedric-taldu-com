<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\HomeLayout;
use PHPUnit\Framework\TestCase;

/**
 * Disposition de l'accueil : ordre et activation des sections.
 */
final class HomeLayoutTest extends TestCase
{
    public function test_par_defaut_toutes_les_sections_sont_activees_dans_l_ordre_canonique(): void
    {
        $this->assertSame(array_keys(HomeLayout::SECTIONS), HomeLayout::default()->enabledOrder());
    }

    public function test_une_section_desactivee_disparait_de_l_ordre_affiche(): void
    {
        $layout = HomeLayout::fromStored([
            ['section' => 'hero', 'enabled' => false],
            ['section' => 'contact', 'enabled' => true],
        ]);

        $this->assertNotContains('hero', $layout->enabledOrder());
        $this->assertContains('contact', $layout->enabledOrder());
    }

    public function test_les_sections_absentes_du_reglage_sont_ajoutees_a_la_fin(): void
    {
        // Réglage partiel : contact d'abord, le reste (dont hero) complété ensuite.
        $layout = HomeLayout::fromStored([['section' => 'contact', 'enabled' => true]]);

        $ordre = $layout->enabledOrder();

        $this->assertSame('contact', $ordre[0]);
        $this->assertContains('hero', $ordre);
        $this->assertCount(count(HomeLayout::SECTIONS), $layout->forAdmin());
    }

    public function test_une_clef_inconnue_est_ignoree(): void
    {
        $layout = HomeLayout::fromStored([['section' => 'evil', 'enabled' => true]]);

        $sections = array_column($layout->forAdmin(), 'section');

        $this->assertNotContains('evil', $sections);
        $this->assertCount(count(HomeLayout::SECTIONS), $sections);
    }

    public function test_from_input_ordonne_par_position_et_active_selon_les_cases(): void
    {
        $layout = HomeLayout::fromInput(
            ['contact' => 1, 'hero' => 2],
            ['contact' => true, 'hero' => true],
        );

        // Seules contact et hero sont activées ; contact (pos 1) avant hero (pos 2).
        $this->assertSame(['contact', 'hero'], $layout->enabledOrder());
    }
}
