<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §10 : « .htaccess interdit .env, .git, composer.*, *.md, *.sql,
 * storage/, src/, vendor/, migrations/, tests/, docker/. »
 *
 * Deux niveaux de defense, tous deux verifies :
 *
 *  1. La disposition des fichiers : rien de sensible n'est sous public/, qui
 *     est le seul dossier expose. C'est la defense reelle.
 *  2. Les regles .htaccess : elles couvrent le cas du mutualise ou le
 *     DocumentRoot ne peut pas etre deplace. C'est le filet.
 *
 * S'y ajoute le comportement de l'application elle-meme : aucune de ces URL ne
 * correspond a une route, donc toutes repondent 404.
 */
final class ExposureTest extends FunctionalTestCase
{
    private static function racine(): string
    {
        return dirname(__DIR__, 2);
    }

    // ------------------------------------------------- disposition des fichiers

    /**
     * @return iterable<string, array{string}>
     */
    public static function dossiersSensibles(): iterable
    {
        yield 'sources' => ['src'];
        yield 'tests' => ['tests'];
        yield 'migrations' => ['migrations'];
        yield 'stockage' => ['storage'];
        yield 'docker' => ['docker'];
        yield 'documentation' => ['docs'];
        yield 'configuration' => ['config'];
        yield 'gabarits' => ['templates'];
        yield 'maquettes' => ['maquette'];
        yield 'scripts' => ['bin'];
    }

    #[DataProvider('dossiersSensibles')]
    public function test_aucun_dossier_sensible_ne_se_trouve_sous_public(string $dossier): void
    {
        $this->assertDirectoryDoesNotExist(
            self::racine() . '/public/' . $dossier,
            sprintf('« %s » ne doit jamais se trouver sous public/.', $dossier)
        );
    }

    public function test_aucun_secret_ne_se_trouve_sous_public(): void
    {
        foreach (['.env', '.env.example', 'composer.json', 'composer.lock', 'phpunit.xml'] as $fichier) {
            $this->assertFileDoesNotExist(self::racine() . '/public/' . $fichier);
        }
    }

    public function test_public_ne_contient_que_ce_qui_doit_etre_servi(): void
    {
        // Un fichier depose par erreur dans public/ y reste servi indefiniment.
        $attendus = ['.', '..', '.htaccess', 'index.php', 'robots.txt', 'assets', 'media'];

        $trouves = scandir(self::racine() . '/public') ?: [];

        $this->assertSame([], array_values(array_diff($trouves, $attendus)));
    }

    public function test_le_dossier_de_stockage_est_hors_webroot(): void
    {
        // 06-securite §5.6 : les originaux televerses ne sont jamais publics.
        $this->assertDirectoryExists(self::racine() . '/storage');
        $this->assertDirectoryDoesNotExist(self::racine() . '/public/storage');
    }

    // ------------------------------------------------------ regles .htaccess

    /**
     * @return iterable<string, array{string}>
     */
    public static function cheminsInterditsParHtaccess(): iterable
    {
        yield '.git' => ['\.git'];
        yield 'storage' => ['storage'];
        yield 'src' => ['src'];
        yield 'vendor' => ['vendor'];
        yield 'migrations' => ['migrations'];
        yield 'tests' => ['tests'];
        yield 'docker' => ['docker'];
        yield 'config' => ['config'];
        yield 'templates' => ['templates'];
    }

    #[DataProvider('cheminsInterditsParHtaccess')]
    public function test_le_htaccess_racine_interdit_les_chemins_sensibles(string $motif): void
    {
        $htaccess = (string) file_get_contents(self::racine() . '/.htaccess');

        $this->assertStringContainsString($motif, $htaccess);
    }

    public function test_le_htaccess_racine_interdit_les_fichiers_de_service(): void
    {
        $htaccess = (string) file_get_contents(self::racine() . '/.htaccess');

        foreach (['env', 'log', 'sql', 'md', 'lock'] as $extension) {
            $this->assertStringContainsString($extension, $htaccess);
        }
    }

    public function test_le_listing_de_repertoire_est_desactive_partout(): void
    {
        foreach (['/.htaccess', '/public/.htaccess'] as $fichier) {
            $this->assertStringContainsString(
                'Options -Indexes',
                (string) file_get_contents(self::racine() . $fichier),
                $fichier
            );
        }
    }

    public function test_l_execution_php_est_coupee_dans_storage_et_dans_les_medias(): void
    {
        // 06-securite §5.7 : ceinture et bretelles avec le stockage hors
        // webroot. Un fichier televerse qui passerait tous les controles ne
        // doit toujours pas pouvoir s'executer.
        foreach (['/storage/.htaccess', '/public/media/.htaccess'] as $fichier) {
            $contenu = (string) file_get_contents(self::racine() . $fichier);

            $this->assertStringContainsString('php_flag engine off', $contenu, $fichier);
            $this->assertStringContainsString('RemoveHandler', $contenu, $fichier);
            $this->assertStringContainsString('SetHandler none', $contenu, $fichier);
        }
    }

    public function test_la_signature_du_serveur_est_masquee(): void
    {
        $this->assertStringContainsString(
            'ServerSignature Off',
            (string) file_get_contents(self::racine() . '/public/.htaccess')
        );
    }

    // ---------------------------------------------------- comportement du site

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsSensibles(): iterable
    {
        yield '.env' => ['/cedric-taldu/.env'];
        yield 'dépôt Git' => ['/cedric-taldu/.git/config'];
        yield 'sources' => ['/cedric-taldu/src/Core/Config.php'];
        yield 'stockage' => ['/cedric-taldu/storage/logs/app.log'];
        yield 'dépendances' => ['/cedric-taldu/vendor/autoload.php'];
        yield 'migrations' => ['/cedric-taldu/migrations/0001_init.sql'];
        yield 'tests' => ['/cedric-taldu/tests/bootstrap.php'];
        yield 'composer' => ['/cedric-taldu/composer.json'];
        yield 'phpinfo' => ['/cedric-taldu/phpinfo.php'];
    }

    #[DataProvider('urlsSensibles')]
    public function test_aucune_url_sensible_n_est_servie_par_l_application(string $uri): void
    {
        $reponse = $this->get($uri);

        $this->assertSame(404, $reponse->status);
    }

    #[DataProvider('urlsSensibles')]
    public function test_la_reponse_a_une_url_sensible_ne_confirme_rien(string $uri): void
    {
        // 06-securite §8 : pas d'enumeration. Une 404 identique dans tous les
        // cas ne dit pas si le fichier existe.
        $corps = $this->get($uri)->body;

        $this->assertStringNotContainsString('storage', $corps);
        $this->assertStringNotContainsString('vendor', $corps);
        $this->assertStringNotContainsString('.env', $corps);
    }
}
