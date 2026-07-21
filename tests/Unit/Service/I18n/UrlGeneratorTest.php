<?php

declare(strict_types=1);

namespace Tests\Unit\Service\I18n;

use App\Core\Config;
use App\Core\Env;
use App\Core\Exception\AssetNotFound;
use App\Core\Exception\InvalidRouteParameter;
use App\Core\Route;
use App\Core\Router;
use App\Service\I18n\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ControleurFactice;

#[CoversClass(UrlGenerator::class)]
final class UrlGeneratorTest extends TestCase
{
    private const CONTROLEUR = [ControleurFactice::class, 'index'];
    private const PUBLIC_DIR = __DIR__ . '/../../../Support/fixtures/public';

    private function routeur(): Router
    {
        return new Router([
            new Route('home', 'GET', '/fr/', self::CONTROLEUR, locale: 'fr'),
            new Route('home', 'GET', '/en/', self::CONTROLEUR, locale: 'en'),
            new Route(
                'artwork.show',
                'GET',
                '/fr/oeuvre/{slug}',
                self::CONTROLEUR,
                locale: 'fr',
                requirements: ['slug' => Route::SLUG],
            ),
            new Route(
                'artwork.show',
                'GET',
                '/en/artwork/{slug}',
                self::CONTROLEUR,
                locale: 'en',
                requirements: ['slug' => Route::SLUG],
            ),
            new Route(
                'category.show',
                'GET',
                '/fr/galerie/{slug}',
                self::CONTROLEUR,
                locale: 'fr',
                requirements: ['slug' => Route::SLUG],
            ),
            new Route('stripe.webhook', 'POST', '/webhooks/stripe', self::CONTROLEUR, csrfExempt: true),
        ]);
    }

    /**
     * @param array<string, string> $surcharges
     */
    private function config(array $surcharges = []): Config
    {
        return Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'preprod',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'APP_BASE_PATH' => '/cedric-taldu',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => '',
            'SECURITY_PEPPER' => str_repeat('a', 64),
            ...$surcharges,
        ]));
    }

    private function generateur(string $basePath = '/cedric-taldu'): UrlGenerator
    {
        return new UrlGenerator($this->routeur(), $this->config(), $basePath, self::PUBLIC_DIR);
    }

    // ------------------------------------------------------------- préfixe

    public function test_une_url_de_route_porte_le_prefixe_de_chemin(): void
    {
        $this->assertSame('/cedric-taldu/fr/', $this->generateur()->route('home', ['locale' => 'fr']));
    }

    public function test_la_meme_route_sans_prefixe_en_production(): void
    {
        $this->assertSame('/fr/', $this->generateur('')->route('home', ['locale' => 'fr']));
    }

    public function test_le_prefixe_vient_de_la_requete_et_non_de_la_configuration(): void
    {
        // Derriere Heimdall, le prefixe peut venir de X-Forwarded-Prefix : c'est
        // la valeur resolue par Request qui fait foi (09-environnements §3.2).
        $generateur = new UrlGenerator($this->routeur(), $this->config(), '/autre-prefixe', self::PUBLIC_DIR);

        $this->assertSame('/autre-prefixe/fr/', $generateur->route('home', ['locale' => 'fr']));
    }

    // ------------------------------------------------------------ paramètres

    public function test_un_parametre_de_chemin_est_substitue(): void
    {
        $url = $this->generateur()->route('artwork.show', ['locale' => 'fr', 'slug' => 'articulation']);

        $this->assertSame('/cedric-taldu/fr/oeuvre/articulation', $url);
    }

    public function test_la_langue_choisit_le_segment_traduit(): void
    {
        $url = $this->generateur()->route('artwork.show', ['locale' => 'en', 'slug' => 'articulation']);

        $this->assertSame('/cedric-taldu/en/artwork/articulation', $url);
    }

    public function test_un_parametre_manquant_est_une_erreur_de_programmation(): void
    {
        $this->expectException(InvalidRouteParameter::class);

        $this->generateur()->route('artwork.show', ['locale' => 'fr']);
    }

    public function test_un_parametre_qui_viole_sa_contrainte_est_refuse(): void
    {
        // Mieux vaut une exception a l'ecriture qu'un lien casse en production,
        // ou pire, une valeur non echappee injectee dans un href.
        $this->expectException(InvalidRouteParameter::class);

        $this->generateur()->route('artwork.show', ['locale' => 'fr', 'slug' => '<script>']);
    }

    public function test_un_parametre_surnumeraire_devient_une_chaine_de_requete(): void
    {
        $url = $this->generateur()->route('category.show', [
            'locale' => 'fr',
            'slug' => 'encres',
            'serie' => 'piliers',
        ]);

        $this->assertSame('/cedric-taldu/fr/galerie/encres?serie=piliers', $url);
    }

    public function test_la_chaine_de_requete_est_encodee(): void
    {
        $url = $this->generateur()->route('category.show', [
            'locale' => 'fr',
            'slug' => 'encres',
            'recherche' => 'corps vécu & divisible',
        ]);

        $this->assertStringContainsString('recherche=corps+v%C3%A9cu+%26+divisible', $url);
        $this->assertStringNotContainsString(' ', $url);
    }

    public function test_une_route_non_localisee_se_genere_sans_langue(): void
    {
        $this->assertSame('/cedric-taldu/webhooks/stripe', $this->generateur()->route('stripe.webhook'));
    }

    // -------------------------------------------------------------- absolues

    public function test_une_url_absolue_est_construite_depuis_app_url(): void
    {
        // 05-i18n-seo §5 : jamais depuis l'en-tete Host, sinon un empoisonnement
        // de cache par en-tete reecrit les canoniques et les liens des e-mails.
        $url = $this->generateur()->absolute('artwork.show', ['locale' => 'fr', 'slug' => 'articulation']);

        $this->assertSame('https://customer.phracktale.com/cedric-taldu/fr/oeuvre/articulation', $url);
    }

    public function test_une_url_absolue_ne_double_pas_le_prefixe(): void
    {
        $this->assertSame(
            'https://customer.phracktale.com/cedric-taldu/fr/',
            $this->generateur()->absolute('home', ['locale' => 'fr'])
        );
    }

    // ---------------------------------------------------------------- assets

    public function test_un_asset_porte_le_prefixe_et_une_empreinte_de_version(): void
    {
        $url = $this->generateur()->asset('css/site.css');

        $this->assertMatchesRegularExpression(
            '#^/cedric-taldu/assets/css/site\.css\?v=[0-9a-f]{8}$#',
            $url
        );
    }

    public function test_l_empreinte_depend_du_contenu_du_fichier(): void
    {
        $generateur = $this->generateur();

        $this->assertNotSame(
            $generateur->asset('css/site.css'),
            $generateur->asset('js/app.js')
        );
    }

    public function test_l_empreinte_est_stable_d_un_appel_a_l_autre(): void
    {
        $generateur = $this->generateur();

        $this->assertSame($generateur->asset('css/site.css'), $generateur->asset('css/site.css'));
    }

    public function test_un_derive_d_image_porte_le_prefixe(): void
    {
        // Les dérivés de public/media/ sont ENGENDRÉS à l'upload : contrairement
        // aux assets, leur existence ne peut pas être vérifiée au moment où le
        // lien est produit.
        $this->assertSame(
            '/cedric-taldu/media/articulation-1024.avif',
            $this->generateur()->media('articulation-1024.avif')
        );
    }

    #[DataProvider('nomsDeDerivesMalveillants')]
    public function test_un_nom_de_derive_malforme_est_refuse(string $nom): void
    {
        // Le nom vient de la base, pas du client — mais il finit dans une URL,
        // et un nom malformé y produirait une injection d'attribut.
        $this->expectException(AssetNotFound::class);

        $this->generateur()->media($nom);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nomsDeDerivesMalveillants(): iterable
    {
        yield 'remontée' => ['../../../.env'];
        yield 'sous-dossier' => ['a/b.avif'];
        yield 'guillemet' => ['a".avif'];
        yield 'espace' => ['a b.avif'];
        yield 'sans extension' => ['articulation-1024'];
        yield 'extension inattendue' => ['articulation-1024.php'];
        yield 'vide' => [''];
    }

    public function test_un_asset_absent_est_une_erreur_bruyante(): void
    {
        // Un lien vers un fichier inexistant se voit en production sous la forme
        // d'une page sans style : on prefere l'apprendre par un test.
        $this->expectException(AssetNotFound::class);

        $this->generateur()->asset('css/inexistant.css');
    }

    #[DataProvider('assetsMalveillants')]
    public function test_un_asset_sortant_du_dossier_public_est_refuse(string $chemin): void
    {
        $this->expectException(AssetNotFound::class);

        $this->generateur()->asset($chemin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function assetsMalveillants(): iterable
    {
        yield 'remontée' => ['../../../.env'];
        yield 'remontée au milieu' => ['css/../../../.env'];
        yield 'chemin absolu' => ['/etc/passwd'];
        yield 'octet nul' => ["css/site.css\0.png"];
        yield 'antislash' => ['..\\..\\.env'];
    }
}
