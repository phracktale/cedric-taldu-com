<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Menu mobile (demande du 2026-10-13) : sous 900 px, la barre ne garde que le
 * logo, le panier et le burger ; compte, langues et coordonnées passent dans
 * le menu. Au-delà, ces reprises n'existent pas à l'écran.
 */
final class MenuMobileStylesTest extends TestCase
{
    private static function css(): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/site.css');

        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    public function test_les_reprises_du_menu_mobile_sont_masquees_sur_grand_ecran(): void
    {
        $this->assertMatchesRegularExpression('~(?:^|})\s*\.menu-mobile\s*\{\s*display:\s*none;~m', self::css());
    }

    public function test_sous_900_px_la_barre_ne_garde_que_logo_panier_et_burger(): void
    {
        $mobile = self::mobile();

        $this->assertMatchesRegularExpression('~\.outils-entete \.compte-lien,\s*\.outils-entete \.langues\s*\{\s*display:\s*none;~', $mobile);
        $this->assertMatchesRegularExpression('~\.menu-mobile\s*\{\s*display:\s*block;~', $mobile);
        // Le nom du site ne déborde plus sur le bandeau : la barre grandit avec lui.
        $this->assertMatchesRegularExpression('~\.nav-haut\s*\{[^}]*height:\s*auto;~', $mobile);
    }

    public function test_les_regles_du_menu_ne_touchent_plus_le_fil_d_ariane(): void
    {
        // « nav li » et « nav a » visaient TOUTE balise <nav> : le fil d'Ariane
        // s'empilait sur quatre lignes sous 900 px. Elles sont bornées au menu.
        $mobile = self::mobile();

        $this->assertDoesNotMatchRegularExpression('~(?:^|[;{}])\s*nav li\s*\{~', $mobile);
        $this->assertDoesNotMatchRegularExpression('~(?:^|[;{}])\s*nav a\s*\{~', $mobile);
    }

    /** Contenu des blocs « @media (max-width: 900px) ». */
    private static function mobile(): string
    {
        preg_match_all('~@media \(max-width: 900px\)\s*\{((?:[^{}]|\{[^{}]*\})*)\}~', self::css(), $m);

        return implode("\n", $m[1]);
    }
}
