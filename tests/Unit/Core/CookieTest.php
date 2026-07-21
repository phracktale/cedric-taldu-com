<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Cookie;
use App\Core\CookieFactory;
use App\Core\Exception\InvalidCookie;
use App\Core\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FrozenClock;

#[CoversClass(Cookie::class)]
#[CoversClass(CookieFactory::class)]
final class CookieTest extends TestCase
{
    private function fabrique(string $basePath = '/cedric-taldu', bool $secure = true): CookieFactory
    {
        return new CookieFactory($basePath, $secure, new FrozenClock('2026-07-21 09:30:00'));
    }

    // --------------------------------------------------------- valeur d'en-tête

    public function test_un_cookie_porte_les_trois_attributs_de_securite(): void
    {
        // 06-securite §3 : HttpOnly, Secure, SameSite=Lax sur tous les cookies.
        $entete = $this->fabrique()->make('ct_session', 'abc')->toHeaderValue();

        $this->assertStringContainsString('HttpOnly', $entete);
        $this->assertStringContainsString('Secure', $entete);
        $this->assertStringContainsString('SameSite=Lax', $entete);
    }

    public function test_le_chemin_du_cookie_est_le_prefixe_de_l_application(): void
    {
        // 09-environnements §3.6 : c'est un point de securite, pas de confort.
        // customer.phracktale.com heberge aussi ENERIA : sans Path, les cookies
        // du site fuiteraient vers l'autre application.
        $entete = $this->fabrique()->make('ct_session', 'abc')->toHeaderValue();

        $this->assertStringContainsString('Path=/cedric-taldu', $entete);
    }

    public function test_a_la_racine_le_chemin_du_cookie_est_un_simple_slash(): void
    {
        $entete = $this->fabrique(basePath: '')->make('ct_session', 'abc')->toHeaderValue();

        $this->assertStringContainsString('Path=/;', $entete);
    }

    public function test_l_attribut_secure_est_omis_hors_https(): void
    {
        // En developpement local le site est servi en http://localhost:18120 :
        // un cookie Secure ne serait jamais renvoye et la session serait perdue.
        $entete = $this->fabrique(secure: false)->make('ct_session', 'abc')->toHeaderValue();

        $this->assertStringNotContainsString('Secure', $entete);
        $this->assertStringContainsString('HttpOnly', $entete);
    }

    public function test_un_cookie_de_session_n_a_pas_de_date_d_expiration(): void
    {
        $entete = $this->fabrique()->make('ct_session', 'abc')->toHeaderValue();

        $this->assertStringNotContainsString('Expires=', $entete);
        $this->assertStringNotContainsString('Max-Age=', $entete);
    }

    public function test_un_cookie_persistant_porte_expires_et_max_age(): void
    {
        $entete = $this->fabrique()->make('ct_locale', 'fr', 31536000)->toHeaderValue();

        $this->assertStringContainsString('Max-Age=31536000', $entete);
        $this->assertStringContainsString('Expires=Wed, 21 Jul 2027 09:30:00 GMT', $entete);
    }

    public function test_la_valeur_est_encodee(): void
    {
        $entete = $this->fabrique()->make('ct_locale', 'fr;path=/')->toHeaderValue();

        $this->assertStringContainsString('ct_locale=fr%3Bpath%3D%2F;', $entete);
    }

    public function test_la_suppression_produit_un_cookie_deja_expire(): void
    {
        $entete = $this->fabrique()->forget('ct_cart')->toHeaderValue();

        $this->assertStringContainsString('ct_cart=;', $entete);
        $this->assertStringContainsString('Max-Age=0', $entete);
    }

    // ------------------------------------------------------------ nommage

    public function test_le_nom_doit_porter_le_prefixe_de_l_application(): void
    {
        // 09-environnements §3.7 : sans prefixe, une collision avec ENERIA sur
        // customer.phracktale.com ferait ecraser une session par l'autre.
        $this->expectException(InvalidCookie::class);

        $this->fabrique()->make('session', 'abc');
    }

    #[DataProvider('nomsInvalides')]
    public function test_un_nom_de_cookie_malforme_est_refuse(string $nom): void
    {
        $this->expectException(InvalidCookie::class);

        $this->fabrique()->make($nom, 'abc');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nomsInvalides(): iterable
    {
        yield 'point-virgule' => ['ct_a;b'];
        yield 'espace' => ['ct_a b'];
        yield 'saut de ligne' => ["ct_a\nb"];
        yield 'égal' => ['ct_a=b'];
        yield 'vide' => [''];
    }

    // -------------------------------------------------- attachement à la réponse

    public function test_un_cookie_devient_une_en_tete_set_cookie(): void
    {
        $reponse = (new Response())->withCookie($this->fabrique()->make('ct_session', 'abc'));

        $this->assertCount(1, $reponse->cookies);
        $this->assertStringStartsWith('ct_session=abc;', $reponse->cookies[0]->toHeaderValue());
    }

    public function test_deux_cookies_differents_coexistent(): void
    {
        $fabrique = $this->fabrique();

        $reponse = (new Response())
            ->withCookie($fabrique->make('ct_session', 'abc'))
            ->withCookie($fabrique->make('ct_cart', 'def'));

        $this->assertCount(2, $reponse->cookies);
    }

    public function test_le_meme_cookie_defini_deux_fois_ne_part_qu_une_fois(): void
    {
        $fabrique = $this->fabrique();

        $reponse = (new Response())
            ->withCookie($fabrique->make('ct_session', 'ancien'))
            ->withCookie($fabrique->make('ct_session', 'nouveau'));

        $this->assertCount(1, $reponse->cookies);
        $this->assertStringStartsWith('ct_session=nouveau;', $reponse->cookies[0]->toHeaderValue());
    }

    public function test_la_reponse_reste_immuable_quand_on_y_pose_un_cookie(): void
    {
        $origine = new Response();

        $origine->withCookie($this->fabrique()->make('ct_session', 'abc'));

        $this->assertSame([], $origine->cookies);
    }
}
