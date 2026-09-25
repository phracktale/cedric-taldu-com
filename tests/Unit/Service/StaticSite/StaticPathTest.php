<?php

declare(strict_types=1);

namespace Tests\Unit\Service\StaticSite;

use App\Service\StaticSite\StaticPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Génération statique (retours du 2026-09-25, point 7) : un chemin public
 * devient un fichier `{chemin}/index.html`, exactement là où la réécriture
 * Apache le cherchera.
 */
final class StaticPathTest extends TestCase
{
    public function test_un_chemin_devient_un_index_html_dans_son_dossier(): void
    {
        $this->assertSame('fr/galerie/encres/index.html', StaticPath::fileFor('/fr/galerie/encres'));
        $this->assertSame('fr/index.html', StaticPath::fileFor('/fr/'));
        $this->assertSame('en/works/index.html', StaticPath::fileFor('/en/works'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cheminsRefuses(): iterable
    {
        yield 'racine' => ['/'];
        yield 'vide' => [''];
        yield 'remontée' => ['/fr/../../etc'];
        yield 'point' => ['/fr/./galerie'];
        yield 'requête' => ['/fr/galerie?page=2'];
        yield 'fichier caché' => ['/fr/.htaccess'];
        yield 'majuscules et accents' => ['/fr/Galerie/é'];
    }

    #[DataProvider('cheminsRefuses')]
    public function test_un_chemin_hors_du_format_des_routes_est_refuse(string $chemin): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StaticPath::fileFor($chemin);
    }
}
