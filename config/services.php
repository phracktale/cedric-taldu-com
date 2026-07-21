<?php

/**
 * Cablage du conteneur.
 *
 * Ecrit a la main, sans reflexion ni auto-decouverte : ce fichier est la reponse
 * exhaustive a « qui depend de quoi ». Les tests fonctionnels le chargent tel
 * quel et ne remplacent que la session et le journal, pour que la chaine testee
 * soit la meme qu'en production.
 *
 * Le prefixe de chemin et le caractere securise viennent de la REQUETE, pas
 * seulement de la configuration : derriere Heimdall, ils peuvent etre determines
 * par X-Forwarded-Prefix et X-Forwarded-Proto (09-environnements §3.2 et §4).
 */

declare(strict_types=1);

use App\Core\ClockInterface;
use App\Core\Config;
use App\Core\Container;
use App\Core\CookieFactory;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\ErrorResponder;
use App\Core\FileLogger;
use App\Core\Kernel;
use App\Core\LoggerInterface;
use App\Core\PhpSession;
use App\Core\RandomInterface;
use App\Core\Request;
use App\Core\Router;
use App\Core\SecureRandom;
use App\Core\SessionInterface;
use App\Core\SystemClock;
use App\Core\View;
use App\Http\Controller\Front\ArtworkController;
use App\Http\Controller\Front\CategoryController;
use App\Http\Controller\Front\HomeController;
use App\Http\Middleware\CsrfGuard;
use App\Http\Middleware\Locale;
use App\Http\Middleware\SecurityHeaders;
use App\Repository\ArtworkRepository;
use App\Repository\CategoryRepository;
use App\Repository\MediaRepository;
use App\Repository\SeriesRepository;
use App\Repository\SettingRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\View\Chrome;

return static function (Config $config, Request $request, string $rootPath, ?Env $env = null): Container {
    // La connexion se construit depuis l'environnement, comme le reste. Le
    // parametre permet aux tests de fournir le leur sans toucher au fichier.
    $env ??= Env::load($rootPath . '/.env', array_filter(getenv(), 'is_string'));

    $container = new Container();

    $container->instance(Config::class, $config);
    $container->instance(Request::class, $request);

    $container->set(ClockInterface::class, static fn (): ClockInterface => new SystemClock());
    $container->set(RandomInterface::class, static fn (): RandomInterface => new SecureRandom());

    $container->set(LoggerInterface::class, static fn (Container $c): LoggerInterface => new FileLogger(
        $rootPath . '/storage/logs',
        $c->get(ClockInterface::class),
        $c->get(RandomInterface::class),
    ));

    $container->set(Router::class, static function () use ($rootPath): Router {
        /** @var list<App\Core\Route> $routes */
        $routes = require $rootPath . '/config/routes.php';

        return new Router($routes);
    });

    $container->set(UrlGenerator::class, static fn (Container $c): UrlGenerator => new UrlGenerator(
        $c->get(Router::class),
        $config,
        $request->basePath,
        $rootPath . '/public',
    ));

    $container->set(View::class, static fn (Container $c): View => new View(
        $rootPath . '/templates',
        $c->get(UrlGenerator::class),
    ));

    $container->set(CookieFactory::class, static fn (Container $c): CookieFactory => new CookieFactory(
        $request->basePath,
        $request->secure,
        $c->get(ClockInterface::class),
    ));

    // La session demarre PARESSEUSEMENT, au premier acces reel : une lecture
    // anonyme ne pose aucun cookie et reste mettable en cache.
    $container->set(SessionInterface::class, static fn (): SessionInterface => new PhpSession(
        $request->basePath,
        $request->secure,
        $rootPath . '/storage/sessions',
    ));

    $container->set(Csrf::class, static fn (Container $c): Csrf => new Csrf(
        $c->get(SessionInterface::class),
        $c->get(RandomInterface::class),
    ));

    $container->set(ErrorResponder::class, static fn (Container $c): ErrorResponder => new ErrorResponder(
        $c->get(View::class),
        $config,
        $c->get(LoggerInterface::class),
    ));

    // --- Base de donnees et depots ----------------------------------------

    // Une seule connexion par requete : le conteneur memorise ses services.
    $container->set(PDO::class, static fn (): PDO => Database::fromEnv($env)->connect());

    $container->set(
        CategoryRepository::class,
        static fn (Container $c): CategoryRepository => new CategoryRepository($c->get(PDO::class)),
    );
    $container->set(
        SeriesRepository::class,
        static fn (Container $c): SeriesRepository => new SeriesRepository($c->get(PDO::class)),
    );
    $container->set(
        ArtworkRepository::class,
        static fn (Container $c): ArtworkRepository => new ArtworkRepository($c->get(PDO::class)),
    );
    $container->set(
        MediaRepository::class,
        static fn (Container $c): MediaRepository => new MediaRepository($c->get(PDO::class)),
    );
    $container->set(
        SettingRepository::class,
        static fn (Container $c): SettingRepository => new SettingRepository($c->get(PDO::class)),
    );

    // --- Presentation ------------------------------------------------------

    $container->set(Chrome::class, static fn (Container $c): Chrome => new Chrome(
        $c->get(CategoryRepository::class),
        $config,
        $c->get(ClockInterface::class),
    ));

    // --- Controleurs ------------------------------------------------------

    $container->set(HomeController::class, static fn (Container $c): HomeController => new HomeController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(SettingRepository::class),
        $c->get(ArtworkRepository::class),
        $c->get(MediaRepository::class),
        $c->get(UrlGenerator::class),
    ));

    $container->set(CategoryController::class, static fn (Container $c): CategoryController => new CategoryController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(CategoryRepository::class),
        $c->get(SeriesRepository::class),
        $c->get(ArtworkRepository::class),
        $c->get(MediaRepository::class),
        $c->get(UrlGenerator::class),
    ));

    $container->set(ArtworkController::class, static fn (Container $c): ArtworkController => new ArtworkController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(ArtworkRepository::class),
        $c->get(CategoryRepository::class),
        $c->get(MediaRepository::class),
        $c->get(UrlGenerator::class),
    ));

    // --- Noyau ------------------------------------------------------------

    $container->set(Kernel::class, static fn (Container $c): Kernel => new Kernel(
        $c->get(Router::class),
        $c,
        $c->get(ErrorResponder::class),
        // Ordre impose par ARCHITECTURE §3. Maintenance, RateLimit et AuthGuard
        // s'inserent ici quand la fonctionnalite qui les justifie arrive.
        [
            new SecurityHeaders($config, $c->get(RandomInterface::class)),
            new Locale($config),
            new CsrfGuard($c->get(Csrf::class), $c->get(LoggerInterface::class)),
        ],
    ));

    return $container;
};
