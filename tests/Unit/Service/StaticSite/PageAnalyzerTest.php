<?php

declare(strict_types=1);

namespace Tests\Unit\Service\StaticSite;

use App\Service\StaticSite\PageAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Mesures EcoIndex d'une page générée, sans navigateur : DOM compté sur le
 * HTML, requêtes et poids retrouvés en suivant les ressources jusqu'aux
 * fichiers de public/ (feuilles de style, polices, modules JS importés,
 * images). Le poids est celui transféré : compressé pour le texte.
 */
final class PageAnalyzerTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/ct-analyse-' . getmypid();
        foreach (['assets/css', 'assets/fonts', 'assets/js', 'media'] as $dossier) {
            if (!is_dir($this->public . '/' . $dossier)) {
                mkdir($this->public . '/' . $dossier, 0777, true);
            }
        }

        file_put_contents($this->public . '/assets/css/site.css', "@font-face { src: url('../fonts/f.woff2'); }\n.a { background: url(data:image/png;base64,AAAA); }\n");
        file_put_contents($this->public . '/assets/fonts/f.woff2', str_repeat('x', 1000));
        file_put_contents($this->public . '/assets/js/app.js', "import { b } from './b.js';\nimport './b.js';\nb();\n");
        file_put_contents($this->public . '/assets/js/b.js', "export function b() {}\n");
        file_put_contents($this->public . '/media/x.jpg', str_repeat('y', 2000));
    }

    protected function tearDown(): void
    {
        foreach (['assets/css/site.css', 'assets/fonts/f.woff2', 'assets/js/app.js', 'assets/js/b.js', 'media/x.jpg'] as $fichier) {
            unlink($this->public . '/' . $fichier);
        }
        foreach (['assets/css', 'assets/fonts', 'assets/js', 'assets', 'media', ''] as $dossier) {
            rmdir($this->public . '/' . $dossier);
        }
    }

    public function test_requetes_poids_et_dom_d_une_page(): void
    {
        $html = '<!DOCTYPE html><html><head>'
            . '<link rel="preload" href="/cedric-taldu/assets/fonts/f.woff2?v=2" as="font">'
            . '<link rel="stylesheet" href="/cedric-taldu/assets/css/site.css?v=1">'
            . '<script type="module" src="/cedric-taldu/assets/js/app.js?v=3"></script>'
            . '<link rel="canonical" href="https://exemple.test/fr/">'
            . '</head><body>'
            . '<img src="/cedric-taldu/media/x.jpg" alt="">'
            . '<img src="https://ailleurs.test/a.png" alt="">'
            . '<img src="data:image/gif;base64,R0lGOD" alt="">'
            . '<svg><g><path d=""/></g></svg>'
            . '</body></html>';

        $mesure = (new PageAnalyzer($this->public))->measure($html, '/cedric-taldu');

        // html, head, link×3, script, body, img×3, svg — les enfants du SVG ne comptent pas.
        $this->assertSame(11, $mesure->dom);
        // Page, feuille, police (préchargée et citée : une fois), app.js, b.js,
        // x.jpg, image externe. Ni le data:, ni le canonical.
        $this->assertSame(7, $mesure->requests);

        $attendu = strlen((string) gzencode($html, 6))
            + strlen((string) gzencode((string) file_get_contents($this->public . '/assets/css/site.css'), 6))
            + 1000
            + strlen((string) gzencode((string) file_get_contents($this->public . '/assets/js/app.js'), 6))
            + strlen((string) gzencode((string) file_get_contents($this->public . '/assets/js/b.js'), 6))
            + 2000;
        $this->assertSame($attendu, $mesure->bytes);
    }

    public function test_une_page_statique_compte_l_appel_a_l_etat_du_visiteur(): void
    {
        $sans = (new PageAnalyzer($this->public))->measure('<html><body></body></html>', '');
        $avec = (new PageAnalyzer($this->public))->measure('<html><body data-static></body></html>', '');

        $this->assertSame($sans->requests + 1, $avec->requests);
    }
}
