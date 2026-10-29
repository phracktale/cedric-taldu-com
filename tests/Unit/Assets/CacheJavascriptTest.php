<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Cache des scripts (retour du 2026-10-29).
 *
 * Les points d'entrée sont chargés par asset(), avec une empreinte (?v=) : ils
 * peuvent être immuables. Les modules qu'ils importent (`import './nav.js'`)
 * n'en portent pas : servis « immutable », un correctif n'atteignait jamais
 * les visiteurs déjà venus. Ils sont revalidés à chaque chargement (304).
 */
final class CacheJavascriptTest extends TestCase
{
    private const IMMUABLE = 'Header set Cache-Control "public, max-age=31536000, immutable"';

    public function test_les_modules_importes_sont_revalides(): void
    {
        $this->assertMatchesRegularExpression(
            '~<FilesMatch "\\\\\.js\$">\s*Header set Cache-Control "no-cache"~',
            self::htaccess(),
        );
    }

    public function test_la_regle_immuable_generale_exclut_les_scripts(): void
    {
        $this->assertSame(1, preg_match(
            '~<FilesMatch "\\\\\.\(([a-z0-9|]+)\)\$">\s*' . preg_quote(self::IMMUABLE, '~') . '~',
            self::htaccess(),
            $regle,
        ));

        $this->assertNotContains('js', explode('|', $regle[1]));
    }

    public function test_seuls_les_scripts_charges_par_asset_restent_immuables(): void
    {
        $this->assertSame(1, preg_match(
            '~<FilesMatch "\^\(([a-z0-9|-]+)\)\\\\\.js\$">\s*' . preg_quote(self::IMMUABLE, '~') . '~',
            self::htaccess(),
            $regle,
        ));

        $immuables = explode('|', $regle[1]);
        sort($immuables);

        $this->assertSame(self::pointsDEntree(), $immuables);
    }

    private static function htaccess(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/public/.htaccess');
    }

    /**
     * Scripts que les gabarits chargent par asset('js/…') : eux seuls ont une
     * URL qui change avec leur contenu.
     *
     * @return list<string>
     */
    private static function pointsDEntree(): array
    {
        $noms = [];
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/templates'));

        foreach ($fichiers as $fichier) {
            if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("~asset\('js/([a-z0-9-]+)\.js'\)~", (string) file_get_contents($fichier->getPathname()), $trouves);

            foreach ($trouves[1] as $nom) {
                $noms[$nom] = true;
            }
        }

        $noms = array_keys($noms);
        sort($noms);

        return $noms;
    }
}
