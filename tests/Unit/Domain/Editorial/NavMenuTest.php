<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\NavMenu;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Menus du site composés par glisser-déposer (retours du 2026-09-25) : menu
 * principal et menu du pied de page, faits de pages, de galeries et de liens.
 * L'ordre est celui de la liste ; aucun numéro de position.
 */
final class NavMenuTest extends TestCase
{
    public function test_le_menu_principal_par_defaut_reprend_le_menu_historique(): void
    {
        $this->assertSame(
            ['page:about', 'gallery', 'news', 'page:booklet', 'contact'],
            array_map(static fn (array $i): string => $i['key'], NavMenu::defaultMain()->items()),
        );
    }

    public function test_le_pied_de_page_par_defaut_reprend_les_liens_legaux(): void
    {
        $this->assertSame(
            ['page:legal', 'page:privacy', 'page:terms', 'contact'],
            array_map(static fn (array $i): string => $i['key'], NavMenu::defaultFooter()->items()),
        );
    }

    public function test_une_composition_postee_est_relue_dans_son_ordre(): void
    {
        $menu = NavMenu::fromJson(json_encode([
            ['type' => 'category', 'ref' => '7', 'labels' => ['fr' => 'Encres', 'en' => '']],
            ['type' => 'works', 'labels' => ['fr' => 'Boutique', 'en' => 'Shop']],
            ['type' => 'link', 'url' => 'https://example.org/expo', 'labels' => ['fr' => 'Expo', 'en' => 'Show']],
            ['type' => 'page', 'ref' => 'about'],
        ], JSON_THROW_ON_ERROR), [7]);

        $this->assertSame(['category:7', 'works', 'link', 'page:about'], array_column($menu->items(), 'key'));
        $this->assertSame('Boutique', $menu->items()[1]['labels']['fr']);
        $this->assertSame('https://example.org/expo', $menu->items()[2]['url']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function entreesRefusees(): iterable
    {
        yield 'type inconnu' => [['type' => 'evil']];
        yield 'galerie inexistante' => [['type' => 'category', 'ref' => '99']];
        yield 'page inconnue' => [['type' => 'page', 'ref' => '../etc']];
        yield 'lien javascript' => [['type' => 'link', 'url' => 'javascript:alert(1)', 'labels' => ['fr' => 'X']]];
        yield 'lien protocole relatif' => [['type' => 'link', 'url' => '//evil.example', 'labels' => ['fr' => 'X']]];
        yield 'lien sans libellé' => [['type' => 'link', 'url' => '/fr/livret']];
    }

    /**
     * @param array<string, mixed> $entree
     */
    #[DataProvider('entreesRefusees')]
    public function test_une_entree_invalide_est_ecartee(array $entree): void
    {
        $menu = NavMenu::fromJson(json_encode([$entree, ['type' => 'contact']], JSON_THROW_ON_ERROR), [7]);

        $this->assertSame(['contact'], array_column($menu->items(), 'key'));
    }

    public function test_les_libelles_sont_nettoyes_et_la_longueur_bornee(): void
    {
        $menu = NavMenu::fromJson(json_encode(array_fill(0, 50, [
            'type' => 'contact', 'labels' => ['fr' => "  Écrire\x00 ", 'en' => str_repeat('x', 200)],
        ]), JSON_THROW_ON_ERROR), []);

        $this->assertCount(NavMenu::MAX_ITEMS, $menu->items());
        $this->assertSame('Écrire', $menu->items()[0]['labels']['fr']);
        $this->assertSame(60, mb_strlen($menu->items()[0]['labels']['en']));
    }

    public function test_un_json_illisible_donne_un_menu_vide(): void
    {
        $this->assertSame([], NavMenu::fromJson('{pas du json', [])->items());
    }

    public function test_l_ancien_reglage_du_menu_principal_est_repris(): void
    {
        // Format précédent (MainMenu) : entrées fixes, affichage, libellés.
        $menu = NavMenu::fromStored([
            ['item' => 'works', 'enabled' => true, 'labels' => ['fr' => 'Boutique', 'en' => '']],
            ['item' => 'about', 'enabled' => false, 'labels' => ['fr' => '', 'en' => '']],
            ['item' => 'contact', 'enabled' => true, 'labels' => ['fr' => '', 'en' => '']],
        ], NavMenu::defaultMain(), []);

        $this->assertSame(['works', 'contact'], array_column($menu->items(), 'key'));
        $this->assertSame('Boutique', $menu->items()[0]['labels']['fr']);
    }

    public function test_sans_reglage_le_defaut_s_applique(): void
    {
        $this->assertSame(
            NavMenu::defaultFooter()->items(),
            NavMenu::fromStored([], NavMenu::defaultFooter(), [])->items(),
        );
    }
}
