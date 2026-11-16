<?php

declare(strict_types=1);

namespace APress\Core\Site;

use APress\Core\Admin\SessionPolicy;
use APress\Core\Auth\AdminSession;
use APress\Core\Auth\AuditTrail;
use APress\Core\Auth\Authenticator;
use APress\Core\Auth\BackupCodes;
use APress\Core\Auth\PasswordHasher;
use APress\Core\Auth\RateLimiter;
use APress\Core\Auth\Throttle;
use APress\Core\Auth\Totp;
use APress\Core\ClockInterface;
use APress\Core\Config;
use APress\Core\Container;
use APress\Core\Content\ContentApi;
use APress\Core\Content\ContentModel;
use APress\Core\Content\EntryValidator;
use APress\Core\Content\PreviewToken;
use APress\Core\CoreMigrations;
use APress\Core\Csrf;
use APress\Core\Database;
use APress\Core\Env;
use APress\Core\ErrorResponder;
use APress\Core\Extension\ExtensionManager;
use APress\Core\Extension\Hooks;
use APress\Core\Extension\Manifest;
use APress\Core\FileLogger;
use APress\Core\Http\Controller\AuthController;
use APress\Core\Http\Controller\ContentApiController;
use APress\Core\Http\Controller\ContentController;
use APress\Core\Content\NullPublishNotifier;
use APress\Core\Content\PublishNotifier;
use APress\Core\Content\WebhookPublishNotifier;
use APress\Core\Http\Controller\DashboardController;
use APress\Core\Http\Middleware\AuthGuard;
use APress\Core\Http\Middleware\CsrfGuard;
use APress\Core\Http\Middleware\SecurityHeaders;
use APress\Core\Kernel;
use APress\Core\LoggerInterface;
use APress\Core\Migrator;
use APress\Core\PhpSession;
use APress\Core\Mail\Mailer;
use APress\Core\Mail\MailerFactory;
use APress\Core\RandomInterface;
use APress\Core\Repository\AuditLogRepository;
use APress\Core\Repository\PdoEntryRepository;
use APress\Core\Repository\PdoExtensionStateStore;
use APress\Core\Repository\RateLimitRepository;
use APress\Core\Repository\UserRepository;
use APress\Core\Router;
use APress\Core\SecureRandom;
use APress\Core\SessionInterface;
use APress\Core\SystemClock;
use APress\Core\Theme\TemplatePaths;
use APress\Core\Ui\AdminShell;
use APress\Core\Ui\AdminView;
use APress\Core\Ui\EditorPanels;
use APress\Core\Ui\UrlBuilder;
use PDO;

/**
 * Un site A-Press complet, câblé par le cœur.
 *
 * Un site n'apporte que son dossier :
 *
 *   content-model.json   le modèle de contenu (obligatoire)
 *   templates/           surcharges des gabarits du cœur (facultatif)
 *   extensions/          ses extensions (facultatif)
 *   public/              webroot ; public/assets/apress/ reçoit les fichiers du cœur
 *   storage/             journaux et sessions
 *
 * et ses variables d'environnement : celles de Config, DB_*, et
 * APRESS_PREVIEW_SECRET / APRESS_PREVIEW_URL pour l'aperçu des brouillons.
 *
 * Le conteneur est paresseux : une requête ne construit que ce qu'elle sert.
 * `$instances` remplace des services (tests, ou un site qui fournit les siens).
 */
final class Application
{
    /**
     * @param array<string, object> $instances
     */
    public static function build(Config $config, Env $env, string $sitePath, array $instances = []): Container
    {
        $c = new Container();
        $c->instance(Config::class, $config);
        $c->instance(Env::class, $env);
        $c->instance(SitePath::class, new SitePath($sitePath));
        $pepper = $env->get('SECURITY_PEPPER');

        $c->set(ClockInterface::class, static fn (): ClockInterface => new SystemClock());
        $c->set(RandomInterface::class, static fn (): RandomInterface => new SecureRandom());
        $c->set(LoggerInterface::class, static fn (Container $c): LoggerInterface
            => new FileLogger($sitePath . '/storage/logs', self::get($c, ClockInterface::class), self::get($c, RandomInterface::class)));
        $c->set(PDO::class, static fn (): PDO => Database::fromEnv($env)->connect());
        $c->set(SessionInterface::class, static fn (): SessionInterface
            => new PhpSession($config->basePath, $config->url !== '' && str_starts_with($config->url, 'https://'), $sitePath . '/storage/sessions'));

        // --- Sécurité et comptes ----------------------------------------------
        $c->set(Csrf::class, static fn (Container $c): Csrf => new Csrf(self::get($c, SessionInterface::class), self::get($c, RandomInterface::class)));
        $c->set(UserRepository::class, static fn (Container $c): UserRepository => new UserRepository(self::get($c, PDO::class)));
        $c->set(AuditTrail::class, static fn (Container $c): AuditTrail
            => new AuditTrail(new AuditLogRepository(self::get($c, PDO::class)), self::get($c, ClockInterface::class), $pepper));
        $c->set(Authenticator::class, static fn (Container $c): Authenticator => new Authenticator(
            self::get($c, UserRepository::class),
            new PasswordHasher(),
            self::get($c, AuditTrail::class),
            self::get($c, ClockInterface::class),
        ));
        $c->set(AdminSession::class, static fn (Container $c): AdminSession => new AdminSession(
            self::get($c, SessionInterface::class),
            self::get($c, UserRepository::class),
            new SessionPolicy($pepper),
            self::get($c, ClockInterface::class),
            self::get($c, Csrf::class),
        ));
        $c->set(Throttle::class, static fn (Container $c): Throttle
            => new RateLimiter(new RateLimitRepository(self::get($c, PDO::class)), self::get($c, ClockInterface::class), $pepper));

        // --- Contenu ------------------------------------------------------------
        $c->set(ContentModel::class, static fn (): ContentModel => ContentModel::fromFile($sitePath . '/content-model.json'));
        $c->set(PdoEntryRepository::class, static fn (Container $c): PdoEntryRepository => new PdoEntryRepository(self::get($c, PDO::class)));
        $c->set(ContentApi::class, static fn (Container $c): ContentApi
            => new ContentApi(self::get($c, ContentModel::class), self::get($c, PdoEntryRepository::class)));
        $c->set(PreviewToken::class, static fn (Container $c): PreviewToken
            => new PreviewToken($env->get('APRESS_PREVIEW_SECRET'), self::get($c, ClockInterface::class)));

        // Générateur du site : prévenu à chaque publication, si configuré.
        $c->set(PublishNotifier::class, static function (Container $c) use ($env): PublishNotifier {
            $url = $env->getOptional('APRESS_BUILD_URL', '') ?? '';

            return $url === ''
                ? new NullPublishNotifier()
                : new WebhookPublishNotifier($url, $env->get('APRESS_BUILD_TOKEN'), self::get($c, LoggerInterface::class));
        });

        // Courriel : aucun envoi tant qu'APRESS_SMTP_HOST n'est pas défini.
        $c->set(Mailer::class, static fn (Container $c): Mailer
            => MailerFactory::fromEnv($env, self::get($c, ClockInterface::class), self::get($c, RandomInterface::class)));

        // --- Extensions -----------------------------------------------------------
        $c->set(ExtensionManager::class, static fn (Container $c): ExtensionManager => new ExtensionManager(
            $sitePath . '/extensions',
            new PdoExtensionStateStore(self::get($c, PDO::class)),
            static function (Manifest $manifest) use ($c): void {
                (new Migrator(self::get($c, PDO::class), $manifest->migrationsDirectory(), self::get($c, ClockInterface::class), $manifest->name))->migrate();
            },
            CoreMigrations::VERSION,
            self::get($c, ClockInterface::class),
            self::get($c, LoggerInterface::class),
        ));
        $c->set(Hooks::class, static function (Container $c): Hooks {
            $hooks = new Hooks();
            self::get($c, ExtensionManager::class)->boot($hooks);
            foreach ($hooks->services() as $id => $factory) {
                if (!$c->has($id)) {
                    $c->set($id, $factory);
                }
            }

            return $hooks;
        });

        // --- Interface d'administration ----------------------------------------------
        $c->set(UrlBuilder::class, static fn (): UrlBuilder => new UrlBuilder($config->basePath, $sitePath . '/public'));
        $c->set(AdminView::class, static fn (Container $c): AdminView => new AdminView(
            new TemplatePaths([
                $sitePath . '/templates',
                ...self::get($c, Hooks::class)->templateDirectories(),
                dirname(__DIR__, 2) . '/templates',
            ]),
            self::get($c, UrlBuilder::class),
        ));
        $c->set(AdminShell::class, static fn (Container $c): AdminShell => new AdminShell(
            self::get($c, AdminView::class),
            $config,
            self::get($c, Csrf::class),
            self::get($c, AdminSession::class),
            self::get($c, SessionInterface::class),
            self::get($c, AuditTrail::class),
            self::get($c, ClockInterface::class),
            self::get($c, ContentModel::class),
            self::get($c, Hooks::class),
        ));

        // --- Contrôleurs --------------------------------------------------------------
        $c->set(AuthController::class, static fn (Container $c): AuthController => new AuthController(
            self::get($c, AdminShell::class),
            self::get($c, AdminSession::class),
            self::get($c, Authenticator::class),
            self::get($c, UserRepository::class),
            self::get($c, Throttle::class),
            new Totp(),
            new BackupCodes($pepper),
        ));
        $c->set(DashboardController::class, static fn (Container $c): DashboardController
            => new DashboardController(self::get($c, AdminShell::class), self::get($c, ContentModel::class), self::get($c, PdoEntryRepository::class)));
        $c->set(ContentController::class, static fn (Container $c): ContentController => new ContentController(
            self::get($c, AdminShell::class),
            self::get($c, ContentModel::class),
            self::get($c, PdoEntryRepository::class),
            new EntryValidator(),
            self::get($c, PreviewToken::class),
            $env->getOptional('APRESS_PREVIEW_URL', '') ?? '',
            self::get($c, PublishNotifier::class),
            new EditorPanels(self::get($c, Hooks::class), $c, self::get($c, LoggerInterface::class)),
        ));
        $c->set(ContentApiController::class, static fn (Container $c): ContentApiController
            => new ContentApiController(self::get($c, ContentApi::class), self::get($c, PreviewToken::class)));

        // --- Noyau ----------------------------------------------------------------------
        $c->set(Router::class, static fn (Container $c): Router
            => new Router([...CoreRoutes::all(), ...self::get($c, Hooks::class)->routes()]));
        $c->set(Kernel::class, static fn (Container $c): Kernel => new Kernel(
            self::get($c, Router::class),
            $c,
            new ErrorResponder(self::get($c, AdminView::class), $config, self::get($c, LoggerInterface::class)),
            [
                new SecurityHeaders($config),
                new CsrfGuard(self::get($c, Csrf::class), self::get($c, LoggerInterface::class)),
                new AuthGuard(self::get($c, AdminSession::class), self::get($c, LoggerInterface::class)),
            ],
        ));

        foreach ($instances as $id => $service) {
            $c->instance($id, $service);
        }

        return $c;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private static function get(Container $c, string $id): object
    {
        $service = $c->get($id);
        assert($service instanceof $id);

        return $service;
    }
}
