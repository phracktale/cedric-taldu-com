<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\MainMenu;
use PHPUnit\Framework\TestCase;

/**
 * Générateur du menu principal (revue du 2026-09-24) : ordre, affichage et
 * libellé des rubriques fixes.
 */
final class MainMenuTest extends TestCase
{
    public function test_par_defaut_le_menu_historique_est_conserve(): void
    {
        // « Toutes les œuvres » existe mais n'est pas affichée par défaut.
        $this->assertSame(
            ['about', 'gallery', 'news', 'booklet', 'contact'],
            array_column(MainMenu::default()->enabledItems(), 'item'),
        );
    }

    public function test_l_ordre_et_l_affichage_suivent_la_saisie(): void
    {
        $menu = MainMenu::fromInput(
            ['works' => 1, 'gallery' => 2, 'contact' => 3],
            ['works' => true, 'gallery' => true, 'contact' => true],
            [],
        );

        $this->assertSame(['works', 'gallery', 'contact'], array_column($menu->enabledItems(), 'item'));
    }

    public function test_un_libelle_personnalise_remplace_le_libelle_par_defaut(): void
    {
        $menu = MainMenu::fromInput(
            ['works' => 1],
            ['works' => true],
            ['works' => ['fr' => 'Boutique', 'en' => 'Shop']],
        );

        $entree = $menu->enabledItems()[0];

        $this->assertSame('Boutique', $entree['labels']['fr']);
        $this->assertSame('Shop', $entree['labels']['en']);
    }

    public function test_un_libelle_est_nettoye_et_borne(): void
    {
        $menu = MainMenu::fromInput(['about' => 1], ['about' => true], [
            'about' => ['fr' => "  L’atelier\x00  ", 'en' => str_repeat('x', 200)],
        ]);

        $libelles = $menu->enabledItems()[0]['labels'];

        $this->assertSame('L’atelier', $libelles['fr']);
        $this->assertSame(60, mb_strlen($libelles['en']));
    }

    public function test_un_reglage_stocke_se_relit_et_ignore_les_entrees_inconnues(): void
    {
        $menu = MainMenu::fromStored([
            ['item' => 'contact', 'enabled' => true, 'labels' => ['fr' => 'Écrire']],
            ['item' => 'evil', 'enabled' => true],
        ]);

        $entrees = $menu->enabledItems();

        $this->assertSame('contact', $entrees[0]['item']);
        $this->assertSame('Écrire', $entrees[0]['labels']['fr']);
        $this->assertNotContains('evil', array_column($menu->forAdmin(), 'item'));
        $this->assertCount(count(MainMenu::ITEMS), $menu->forAdmin());
    }

    public function test_to_array_fait_l_aller_retour(): void
    {
        $menu = MainMenu::fromInput(['news' => 1], ['news' => true], ['news' => ['fr' => 'Journal', 'en' => '']]);

        $relu = MainMenu::fromStored($menu->toArray());

        $this->assertSame($menu->enabledItems(), $relu->enabledItems());
    }
}
