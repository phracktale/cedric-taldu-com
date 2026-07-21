<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Env;
use App\Core\Exception\TemplateNotFound;
use App\Core\Route;
use App\Core\Router;
use App\Core\View;
use App\Service\I18n\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Doubles\ControleurFactice;

#[CoversClass(View::class)]
final class ViewTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../Support/fixtures/templates';
    private const PUBLIC_DIR = __DIR__ . '/../../Support/fixtures/public';

    private function vue(): View
    {
        $config = Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'preprod',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'APP_BASE_PATH' => '/cedric-taldu',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => '',
            'SECURITY_PEPPER' => str_repeat('a', 64),
        ]));

        $routeur = new Router([
            new Route('home', 'GET', '/fr/', [ControleurFactice::class, 'index'], locale: 'fr'),
        ]);

        return new View(
            self::TEMPLATES,
            new UrlGenerator($routeur, $config, '/cedric-taldu', self::PUBLIC_DIR),
        );
    }

    public function test_un_gabarit_est_rendu_avec_ses_donnees(): void
    {
        $html = $this->vue()->render('simple', ['titre' => 'Articulation']);

        $this->assertSame("<h1>Articulation</h1>\n", $html);
    }

    public function test_les_donnees_passent_par_les_helpers_d_echappement(): void
    {
        $html = $this->vue()->render('simple', ['titre' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_le_generateur_d_url_est_disponible_dans_le_gabarit(): void
    {
        // Aucune URL en dur dans un gabarit : le prefixe doit apparaitre.
        $html = $this->vue()->render('avec-url');

        $this->assertStringContainsString('href="/cedric-taldu/fr/"', $html);
    }

    public function test_un_gabarit_peut_en_inclure_un_autre(): void
    {
        $vue = $this->vue();

        $html = $vue->render('avec-partiel', [
            'partiel' => $vue->render('partials/pastille', ['etat' => 'Disponible']),
        ]);

        $this->assertStringContainsString('<span class="dispo">Disponible</span>', $html);
    }

    public function test_un_gabarit_inexistant_leve_une_exception(): void
    {
        $this->expectException(TemplateNotFound::class);

        $this->vue()->render('inexistant');
    }

    public function test_le_tampon_de_sortie_est_referme_si_le_gabarit_echoue(): void
    {
        // Sans cela, une exception en cours de rendu laisse un tampon ouvert et
        // le fragment deja produit se colle a la page d'erreur.
        $niveauAvant = ob_get_level();

        try {
            $this->vue()->render('en-erreur');
            $this->fail('Une exception du gabarit etait attendue.');
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertSame($niveauAvant, ob_get_level());
    }

    #[DataProvider('nomsDeGabaritsMalveillants')]
    public function test_un_nom_de_gabarit_sortant_du_dossier_est_refuse(string $nom): void
    {
        $this->expectException(TemplateNotFound::class);

        $this->vue()->render($nom);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nomsDeGabaritsMalveillants(): iterable
    {
        yield 'remontée' => ['../../../.env'];
        yield 'remontée au milieu' => ['partials/../../.env'];
        yield 'chemin absolu' => ['/etc/passwd'];
        yield 'octet nul' => ["simple\0"];
        yield 'antislash' => ['partials\\pastille'];
        yield 'extension imposée' => ['simple.php'];
        yield 'vide' => [''];
    }

    public function test_la_casse_du_nom_de_gabarit_doit_correspondre_au_systeme_de_fichiers(): void
    {
        // 09-environnements §5 : la casse est significative sur Thor et en
        // production. Un « Simple » qui marche sous Windows casse en ligne :
        // on veut l'apprendre ici.
        $this->expectException(TemplateNotFound::class);

        $this->vue()->render('Simple');
    }
}
