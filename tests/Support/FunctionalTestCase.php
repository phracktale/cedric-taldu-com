<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config;
use App\Core\Container;
use App\Core\Env;
use App\Core\Kernel;
use App\Core\LoggerInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\SessionInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArraySession;
use Tests\Support\Doubles\RecordingLogger;

/**
 * Base des tests fonctionnels.
 *
 * Un test fonctionnel traverse Kernel::handle(Request) et rend une Response.
 * Aucun serveur HTTP n'est demarre, aucune requete reseau n'est emise
 * (tests/CLAUDE.md) : c'est la meme chaine de middlewares et le meme routeur
 * qu'en production, avec la session et le journal doubles.
 */
abstract class FunctionalTestCase extends TestCase
{
    protected RecordingLogger $logger;
    protected ArraySession $session;
    protected Container $container;

    /**
     * Les pages lisent la base : un test fonctionnel a donc besoin d'une
     * connexion, et des memes garanties d'isolement que les tests
     * d'integration — une transaction annulee en tearDown.
     */
    protected \PDO $pdo;

    /** @var array<string, string> */
    private array $env = [];

    /** @var list<\App\Core\Route>|null table de routes de remplacement */
    private ?array $routes = null;

    /** @var array<string, callable(Container): object> services supplementaires */
    private array $services = [];

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->session = new ArraySession();

        $this->pdo = DatabaseTestCase::connect();
        DatabaseTestCase::ensureSchemaFor($this->pdo);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Surcharge de l'environnement, a appeler AVANT la premiere requete.
     *
     * @param array<string, string> $values
     */
    protected function withEnv(array $values): void
    {
        $this->env = [...$this->env, ...$values];
    }

    /**
     * Remplace la table de routes, pour eprouver un comportement du noyau qui
     * n'a pas encore de route reelle — une exception de controleur, par exemple.
     *
     * @param list<\App\Core\Route> $routes
     */
    protected function withRoutes(array $routes): void
    {
        $this->routes = $routes;
    }

    /**
     * Enregistre un service que le cablage de production ne connait pas — le
     * controleur d'un test, par exemple.
     *
     * @param callable(Container): object $factory
     */
    protected function withService(string $id, callable $factory): void
    {
        $this->services[$id] = $factory;
    }

    protected function config(): Config
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
            ...$this->env,
        ]));
    }

    protected function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, string> $cookies
     */
    protected function requete(
        string $method,
        string $uri,
        array $server = [],
        array $query = [],
        array $post = [],
        array $cookies = [],
        ?string $body = null,
    ): Response {
        $config = $this->config();

        $request = Request::fromServer(
            $config,
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $uri,
                'REMOTE_ADDR' => '203.0.113.7',
                ...$server,
            ],
            query: $query,
            post: $post,
            cookies: $cookies,
            body: $body,
        );

        /** @var callable(Config, Request, string): Container $build */
        $build = require $this->rootPath() . '/config/services.php';

        $this->container = $build($config, $request, $this->rootPath());
        $this->container->instance(LoggerInterface::class, $this->logger);
        $this->container->instance(SessionInterface::class, $this->session);
        // La MEME connexion que les fixtures du test : sans cela, la page ne
        // verrait rien de ce que le test vient d'inserer dans sa transaction.
        $this->container->instance(\PDO::class, $this->pdo);

        if ($this->routes !== null) {
            $this->container->instance(\App\Core\Router::class, new \App\Core\Router($this->routes));
        }

        foreach ($this->services as $id => $factory) {
            $this->container->set($id, $factory);
        }

        $kernel = $this->container->get(Kernel::class);
        self::assertInstanceOf(Kernel::class, $kernel);

        return $kernel->handle($request);
    }

    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $cookies
     */
    protected function get(string $uri, array $server = [], array $cookies = []): Response
    {
        return $this->requete('GET', $uri, server: $server, cookies: $cookies);
    }

    /**
     * @param array<string, string> $post
     * @param array<string, mixed>  $server
     */
    protected function post(string $uri, array $post = [], array $server = []): Response
    {
        return $this->requete('POST', $uri, server: $server, post: $post);
    }
}
