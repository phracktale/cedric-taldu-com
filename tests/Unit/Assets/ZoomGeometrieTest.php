<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Visionneuse d'œuvre (demande du 2026-09-30) : la géométrie du zoom vit dans
 * un module JavaScript pur, public/assets/js/zoom-geometrie.js. Ses tests
 * (tests/js) passent par le lanceur intégré de Node — aucune dépendance, et
 * Node n'est requis qu'en développement : sans lui, ce test est ignoré.
 */
final class ZoomGeometrieTest extends TestCase
{
    public function test_la_geometrie_du_zoom_passe_ses_tests_javascript(): void
    {
        exec('node --version 2>&1', $version, $code);
        if ($code !== 0) {
            self::markTestSkipped('Node absent : tests JavaScript non lancés.');
        }

        $racine = dirname(__DIR__, 3);
        exec('node --test ' . escapeshellarg($racine . '/tests/js/zoom-geometrie.test.mjs') . ' 2>&1', $sortie, $code);

        $this->assertSame(0, $code, implode("\n", $sortie));
    }
}
