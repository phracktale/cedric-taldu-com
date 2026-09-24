<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Réglages visuels servis en variables CSS (revue du 2026-09-24).
 */
final class ThemeTest extends TestCase
{
    public function test_le_zoom_est_borne_entre_soixante_et_cent_pour_cent(): void
    {
        $this->assertSame(85, Theme::zoom('85'));
        $this->assertSame(100, Theme::zoom('5000'));
        $this->assertSame(60, Theme::zoom(10));
        $this->assertSame(100, Theme::zoom('85;}body{'));
        $this->assertSame(100, Theme::zoom(null));
    }

    public function test_la_declaration_css_du_zoom_est_un_nombre_decimal(): void
    {
        $this->assertSame('--vignette-zoom: 0.85;', Theme::zoomCss(85));
        $this->assertSame('--vignette-zoom: 0.9;', Theme::zoomCss(90));
        $this->assertSame('--vignette-zoom: 1;', Theme::zoomCss(100));
    }
}
