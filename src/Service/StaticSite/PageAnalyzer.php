<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Mesures EcoIndex d'une page générée, sans navigateur (retours du
 * 2026-09-25, point 8).
 *
 *  - DOM : éléments du document, sans les descendants d'un <svg> (comme la
 *    mesure de référence, qui les retranche).
 *  - Requêtes : la page, puis chaque ressource chargée — feuilles de style et
 *    ce qu'elles citent (polices, images, @import), scripts et les modules
 *    qu'ils importent, images, préchargements, icône —, chacune une seule fois.
 *    Une page statique appelle en plus /api/etat.
 *  - Poids : taille transférée, compressée (gzip) pour le texte, telle quelle
 *    pour les binaires ; une ressource externe compte comme requête, poids
 *    inconnu.
 *
 * Estimation : pour une <picture>, c'est l'image de repli (src) qui est pesée,
 * là où le navigateur choisirait peut-être un dérivé plus léger.
 */
final class PageAnalyzer
{
    private const TEXT = ['html', 'css', 'js', 'mjs', 'svg', 'json', 'txt', 'xml'];

    private const LINK_RELS = ['stylesheet', 'preload', 'modulepreload', 'icon'];

    /** Réponse JSON de /api/etat, en ordre de grandeur. */
    private const STATE_BYTES = 120;

    private readonly string $public;

    /** @var array<string, true> */
    private array $seen = [];

    private int $requests = 0;

    private int $bytes = 0;

    public function __construct(string $publicDirectory)
    {
        $this->public = self::normalize(str_replace('\\', '/', $publicDirectory));
    }

    public function measure(string $html, string $basePath): PageMetrics
    {
        $this->seen = [];
        $this->requests = 1;
        $this->bytes = strlen((string) gzencode($html, 6));

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $dom = 0;
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!self::insideSvg($element)) {
                $dom++;
            }
        }

        foreach ($document->getElementsByTagName('link') as $link) {
            $rels = preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [];
            if (array_intersect($rels, self::LINK_RELS) !== []) {
                $this->load($link->getAttribute('href'), $basePath, null);
            }
        }
        foreach (['script', 'img'] as $balise) {
            foreach ($document->getElementsByTagName($balise) as $element) {
                $this->load($element->getAttribute('src'), $basePath, null);
            }
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ($body instanceof DOMElement && $body->hasAttribute('data-static')) {
            $this->requests++;
            $this->bytes += self::STATE_BYTES;
        }

        return new PageMetrics($dom, $this->requests, $this->bytes);
    }

    private static function insideSvg(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->nodeName === 'svg') {
                return true;
            }
        }

        return false;
    }

    /**
     * Une ressource, comptée une fois ; ses propres dépendances suivent.
     *
     * @param string|null $from fichier citant (CSS ou JS), pour les chemins relatifs
     */
    private function load(string $url, string $basePath, ?string $from): void
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
            return;
        }

        $externe = preg_match('#^(https?:)?//#i', $url) === 1;
        $cle = $externe ? $url : $this->fileFor($url, $basePath, $from);

        if ($cle === null || isset($this->seen[$cle])) {
            return;
        }
        $this->seen[$cle] = true;
        $this->requests++;

        if ($externe || !is_file($cle)) {
            return;
        }

        $contenu = (string) file_get_contents($cle);
        $extension = strtolower(pathinfo($cle, PATHINFO_EXTENSION));
        $this->bytes += in_array($extension, self::TEXT, true) ? strlen((string) gzencode($contenu, 6)) : strlen($contenu);

        $dependances = match ($extension) {
            'css' => self::cssDependencies($contenu),
            'js', 'mjs' => self::jsDependencies($contenu),
            default => [],
        };
        foreach ($dependances as $dependance) {
            $this->load($dependance, $basePath, $cle);
        }
    }

    /**
     * Fichier sous public/ désigné par l'URL, ou null s'il en sortirait.
     */
    private function fileFor(string $url, string $basePath, ?string $from): ?string
    {
        $chemin = (string) preg_replace('/[?#].*$/s', '', $url);

        if (str_starts_with($chemin, '/')) {
            if ($basePath !== '' && str_starts_with($chemin, $basePath . '/')) {
                $chemin = substr($chemin, strlen($basePath));
            }
            $fichier = self::normalize($this->public . $chemin);
        } elseif ($from !== null) {
            $fichier = self::normalize(dirname($from) . '/' . $chemin);
        } else {
            return null;
        }

        return str_starts_with($fichier, $this->public . '/') ? $fichier : null;
    }

    private static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && ($segment !== '' || $i === 0)) {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * @return list<string>
     */
    private static function cssDependencies(string $css): array
    {
        preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $css, $urls);
        preg_match_all('/@import\s+[\'"]([^\'"]+)[\'"]/i', $css, $imports);

        return [...$urls[1], ...$imports[1]];
    }

    /**
     * Imports statiques : `import … from '…'`, `import '…'`, `export … from '…'`.
     *
     * @return list<string>
     */
    private static function jsDependencies(string $js): array
    {
        preg_match_all('/(?:^|[;\s])(?:import|export)\s+(?:[^\'";]*?\s+from\s+)?[\'"]([^\'"]+)[\'"]/m', $js, $imports);

        return $imports[1];
    }
}
