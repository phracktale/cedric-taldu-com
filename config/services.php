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
use App\Core\Validator;
use App\Core\View;
use App\Domain\Admin\SessionPolicy;
use App\Http\Controller\Admin\AccountController;
use App\Http\Controller\Admin\AuthController;
use App\Http\Controller\Admin\ArtworkController as AdminArtworkController;
use App\Http\Controller\Admin\CategoryController as AdminCategoryController;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\MediaController;
use App\Http\Controller\Admin\OrderController as AdminOrderController;
use App\Http\Controller\Admin\ProductController as AdminProductController;
use App\Http\Controller\Front\ArtworkController;
use App\Http\Controller\Front\CartController;
use App\Http\Controller\Front\CheckoutController;
use App\Http\Controller\Front\CategoryController;
use App\Http\Controller\Front\HomeController;
use App\Http\Controller\Front\StripeWebhookController;
use App\Http\Middleware\AuthGuard;
use App\Http\Middleware\CsrfGuard;
use App\Http\Middleware\Locale;
use App\Http\Middleware\RedirectMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Repository\Admin\ArtworkAdminRepository;
use App\Repository\Admin\CategoryAdminRepository;
use App\Repository\Admin\DashboardRepository;
use App\Repository\Admin\MediaAdminRepository;
use App\Repository\Admin\OrderAdminRepository;
use App\Repository\Admin\ProductAdminRepository;
use App\Repository\Admin\SeriesAdminRepository;
use App\Repository\ArtworkRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CategoryRepository;
use App\Repository\MediaRepository;
use App\Repository\ProductRepository;
use App\Repository\RateLimitRepository;
use App\Repository\SeriesRepository;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\Auth\AdminSession;
use App\Service\Auth\AuditTrail;
use App\Service\Auth\Authenticator;
use App\Service\Auth\BackupCodes;
use App\Service\Auth\PasswordHasher;
use App\Service\Auth\Totp;
use App\Service\Content\HtmlSanitizer;
use App\Service\Content\PreviewToken;
use App\Service\Content\TranslationInput;
use App\Service\I18n\Translator;
use App\Service\I18n\UrlGenerator;
use App\Http\Controller\Front\PrintAssetController;
use App\Repository\FulfillmentRepository;
use App\Service\Fulfillment\FulfillmentService;
use App\Service\Fulfillment\PrintAssetStore;
use App\Service\Fulfillment\PrintAssetUrl;
use App\Service\Fulfillment\ProdigiClient;
use App\Service\Fulfillment\ProdigiClientInterface;
use App\Service\Fulfillment\ProdigiConfig;
use App\Service\Media\CoverUpload;
use App\Service\Media\ImageProcessor;
use App\Service\Media\MediaStore;
use App\Service\Media\UploadValidator;
use App\Repository\CartRepository;
use App\Repository\OrderRepository;
use App\Repository\ShippingRepository;
use App\Repository\StockRepository;
use App\Repository\StripeEventRepository;
use App\Repository\VatRepository;
use App\Repository\PersistedOrder;
use App\Service\Mail\MailerInterface;
use App\Service\Mail\OrderMailer;
use App\Service\Mail\SmtpMailer;
use App\Service\Payment\CheckoutService;
use App\Service\Payment\PaymentEventHandler;
use App\Service\Payment\PaymentGateway;
use App\Service\Payment\StripeCheckoutGateway;
use App\Service\Payment\StripeConfig;
use App\Service\Spam\FormTimestamp;
use App\Service\Spam\RateLimiter;
use App\Service\Spam\SpamGuard;
use App\Service\Spam\SpamHeuristics;
use App\Service\Spam\Throttle;
use App\Repository\ContactMessageRepository;
use App\Repository\PostRepository;
use App\Repository\PageRepository;
use App\Repository\RedirectRepository;
use App\Repository\Admin\PostAdminRepository;
use App\Repository\Admin\PageAdminRepository;
use App\Service\Mail\ContactMailer;
use App\Http\Controller\Front\ContactController;
use App\Http\Controller\Front\BlogController;
use App\Http\Controller\Front\PageController;
use App\Http\Controller\Front\SitemapController;
use App\Service\Seo\StructuredData;
use App\Service\Seo\SlugHistory;
use App\Http\Controller\Admin\PostController as AdminPostController;
use App\Http\Controller\Admin\MessageController as AdminMessageController;
use App\Http\Controller\Admin\PageController as AdminPageController;
use Stripe\StripeClient;
use App\Service\View\AdminChrome;
use App\Service\View\Chrome;

return static function (Config $config, Request $request, string $rootPath, ?Env $env = null): Container {
    // La connexion se construit depuis l'environnement, comme le reste. Le
    // parametre permet aux tests de fournir le leur sans toucher au fichier.
    $env ??= Env::load($rootPath . '/.env', array_filter(getenv(), 'is_string'));

    // Resolution AU DEMARRAGE de la configuration Stripe (06-securite §7) :
    // STRIPE_ENV choisit la paire de cles (test | prod), et l'invariant est
    // verifie ici, avant qu'une seule requete ne soit servie — une cle live
    // activee hors production, ou une cle incoherente avec le mode, arrete tout.
    $stripeConfig = StripeConfig::resolve(
        $env->getOptional('STRIPE_ENV', StripeConfig::MODE_TEST) ?? StripeConfig::MODE_TEST,
        $config->env,
        [
            'testKey' => $env->getOptional('STRIPE_TEST_SECRET_KEY', '') ?? '',
            'testWebhook' => $env->getOptional('STRIPE_TEST_WEBHOOK_SECRET', '') ?? '',
            'liveKey' => $env->getOptional('STRIPE_LIVE_SECRET_KEY', '') ?? '',
            'liveWebhook' => $env->getOptional('STRIPE_LIVE_WEBHOOK_SECRET', '') ?? '',
        ],
    );

    // Meme logique pour Prodigi : PRODIGI_ENV choisit la cle (sandbox | live),
    // et le live est refuse hors production (impressions reellement facturees).
    $prodigiConfig = ProdigiConfig::resolve(
        $env->getOptional('PRODIGI_ENV', ProdigiConfig::MODE_SANDBOX) ?? ProdigiConfig::MODE_SANDBOX,
        $config->env,
        [
            'sandboxKey' => $env->getOptional('PRODIGI_SANDBOX_API_KEY', '') ?? '',
            'liveKey' => $env->getOptional('PRODIGI_LIVE_API_KEY', '') ?? '',
        ],
    );

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

    // Traduction de l'interface : catalogues chargés une fois, comportement
    // strict hors production (une clé manquante lève ; en prod elle retombe sur
    // la clé et se journalise, cf. Translator).
    $container->set(Translator::class, static fn (Container $c): Translator => new Translator(
        [
            'fr' => require $rootPath . '/resources/lang/fr.php',
            'en' => require $rootPath . '/resources/lang/en.php',
        ],
        !$config->isProduction(),
        $c->get(LoggerInterface::class),
    ));

    $container->set(View::class, static fn (Container $c): View => new View(
        $rootPath . '/templates',
        $c->get(UrlGenerator::class),
        $c->get(Translator::class),
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
    $container->set(
        UserRepository::class,
        static fn (Container $c): UserRepository => new UserRepository($c->get(PDO::class)),
    );
    $container->set(
        AuditLogRepository::class,
        static fn (Container $c): AuditLogRepository => new AuditLogRepository($c->get(PDO::class)),
    );
    $container->set(
        RateLimitRepository::class,
        static fn (Container $c): RateLimitRepository => new RateLimitRepository($c->get(PDO::class)),
    );
    $container->set(
        DashboardRepository::class,
        static fn (Container $c): DashboardRepository => new DashboardRepository($c->get(PDO::class)),
    );
    $container->set(
        MediaAdminRepository::class,
        static fn (Container $c): MediaAdminRepository => new MediaAdminRepository($c->get(PDO::class)),
    );
    $container->set(
        CategoryAdminRepository::class,
        static fn (Container $c): CategoryAdminRepository => new CategoryAdminRepository($c->get(PDO::class)),
    );
    $container->set(
        SeriesAdminRepository::class,
        static fn (Container $c): SeriesAdminRepository => new SeriesAdminRepository($c->get(PDO::class)),
    );
    $container->set(
        ArtworkAdminRepository::class,
        static fn (Container $c): ArtworkAdminRepository => new ArtworkAdminRepository($c->get(PDO::class)),
    );

    // --- Contenu riche ------------------------------------------------------
    //
    // L'assainissement a lieu A L'ECRITURE (06-securite §2) : TranslationInput
    // le fait pour tous les champs HTML des formulaires, une fois, plutot que
    // dans chaque controleur — ou l'oubli serait invisible.

    $container->set(HtmlSanitizer::class, static fn (): HtmlSanitizer => new HtmlSanitizer());
    $container->set(PreviewToken::class, static fn (Container $c): PreviewToken => new PreviewToken(
        $config->securityPepper,
        $c->get(ClockInterface::class),
    ));
    $container->set(
        TranslationInput::class,
        static fn (Container $c): TranslationInput => new TranslationInput($c->get(HtmlSanitizer::class)),
    );

    // --- Televersement d'images --------------------------------------------
    //
    // Les deux chemins sont donnes ICI et nulle part ailleurs : storage/uploads
    // est hors webroot, public/media ne recoit que des derives re-engendres
    // (06-securite §5.6). Aucun controleur ne construit de chemin de fichier.

    $container->set(UploadValidator::class, static fn (): UploadValidator => new UploadValidator());
    $container->set(ImageProcessor::class, static fn (): ImageProcessor => new ImageProcessor());

    $container->set(MediaStore::class, static fn (Container $c): MediaStore => new MediaStore(
        $c->get(UploadValidator::class),
        $c->get(ImageProcessor::class),
        $c->get(MediaAdminRepository::class),
        $c->get(RandomInterface::class),
        $c->get(ClockInterface::class),
        $rootPath . '/storage/uploads',
        $rootPath . '/public/media',
    ));

    // Resout la couverture d'une entite : fichier joint enregistre dans la
    // mediatheque, sinon identifiant saisi a la main (04-back-office §7).
    $container->set(CoverUpload::class, static fn (Container $c): CoverUpload => new CoverUpload(
        $c->get(MediaStore::class),
    ));

    // Fichier d'impression haute definition par oeuvre (Prodigi) : range hors
    // webroot, SANS re-encodage (source artiste de confiance, pleine resolution).
    $container->set(PrintAssetStore::class, static fn (Container $c): PrintAssetStore => new PrintAssetStore(
        $c->get(RandomInterface::class),
        $rootPath . '/storage/print',
    ));

    // Lectures/écritures du fulfillment Prodigi + jeton d'accès au fichier
    // d'impression (poivre applicatif, domaine dédié).
    $container->set(FulfillmentRepository::class, static fn (Container $c): FulfillmentRepository
        => new FulfillmentRepository($c->get(PDO::class)));
    $container->set(PrintAssetUrl::class, static fn (): PrintAssetUrl => new PrintAssetUrl($config->securityPepper));
    $container->set(PrintAssetController::class, static fn (Container $c): PrintAssetController => new PrintAssetController(
        $c->get(PrintAssetUrl::class),
        $c->get(FulfillmentRepository::class),
        $c->get(PrintAssetStore::class),
    ));

    // Client Prodigi (config resolue au demarrage) et service de soumission.
    $container->instance(ProdigiConfig::class, $prodigiConfig);
    $container->set(
        ProdigiClientInterface::class,
        static fn (Container $c): ProdigiClientInterface => new ProdigiClient($c->get(ProdigiConfig::class)),
    );
    $container->set(FulfillmentService::class, static fn (Container $c): FulfillmentService => new FulfillmentService(
        $c->get(ProdigiClientInterface::class),
        $c->get(ProdigiConfig::class),
        $c->get(FulfillmentRepository::class),
        $c->get(PrintAssetUrl::class),
        $c->get(UrlGenerator::class),
        $c->get(LoggerInterface::class),
    ));

    // --- Authentification --------------------------------------------------
    //
    // Le poivre de .env sert a TROIS usages distincts, chacun avec son propre
    // prefixe de domaine : empreinte de session, empreinte d'IP, empreinte des
    // codes de secours. Le prefixe evite qu'une valeur calculee pour l'un ne
    // soit valable pour l'autre.

    $container->set(Validator::class, static fn (): Validator => new Validator());
    $container->set(PasswordHasher::class, static fn (): PasswordHasher => new PasswordHasher());
    $container->set(Totp::class, static fn (): Totp => new Totp());

    $container->set(
        SessionPolicy::class,
        static fn (): SessionPolicy => new SessionPolicy($config->securityPepper),
    );
    $container->set(
        BackupCodes::class,
        static fn (): BackupCodes => new BackupCodes($config->securityPepper),
    );

    $container->set(AuditTrail::class, static fn (Container $c): AuditTrail => new AuditTrail(
        $c->get(AuditLogRepository::class),
        $c->get(ClockInterface::class),
        $config->securityPepper,
    ));

    $container->set(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter(
        $c->get(RateLimitRepository::class),
        $c->get(ClockInterface::class),
        $config->securityPepper,
    ));

    // La limitation de debit du SpamGuard s'appuie sur le compteur reel.
    $container->set(Throttle::class, static fn (Container $c): Throttle => $c->get(RateLimiter::class));

    $container->set(FormTimestamp::class, static fn (Container $c): FormTimestamp => new FormTimestamp(
        $config->securityPepper,
        $c->get(ClockInterface::class),
    ));

    $container->set(SpamHeuristics::class, static fn (): SpamHeuristics => new SpamHeuristics());

    $container->set(SpamGuard::class, static fn (Container $c): SpamGuard => new SpamGuard(
        $c->get(FormTimestamp::class),
        $c->get(Throttle::class),
        $c->get(SpamHeuristics::class),
    ));

    $container->set(
        ContactMessageRepository::class,
        static fn (Container $c): ContactMessageRepository => new ContactMessageRepository($c->get(PDO::class)),
    );

    $container->set(
        PostRepository::class,
        static fn (Container $c): PostRepository => new PostRepository($c->get(PDO::class)),
    );

    $container->set(
        PostAdminRepository::class,
        static fn (Container $c): PostAdminRepository => new PostAdminRepository($c->get(PDO::class)),
    );

    $container->set(
        PageRepository::class,
        static fn (Container $c): PageRepository => new PageRepository($c->get(PDO::class)),
    );

    $container->set(
        RedirectRepository::class,
        static fn (Container $c): RedirectRepository => new RedirectRepository($c->get(PDO::class)),
    );

    $container->set(
        PageAdminRepository::class,
        static fn (Container $c): PageAdminRepository => new PageAdminRepository($c->get(PDO::class)),
    );

    $container->set(AdminSession::class, static fn (Container $c): AdminSession => new AdminSession(
        $c->get(SessionInterface::class),
        $c->get(UserRepository::class),
        $c->get(SessionPolicy::class),
        $c->get(ClockInterface::class),
        $c->get(Csrf::class),
    ));

    $container->set(Authenticator::class, static fn (Container $c): Authenticator => new Authenticator(
        $c->get(UserRepository::class),
        $c->get(PasswordHasher::class),
        $c->get(AuditTrail::class),
        $c->get(ClockInterface::class),
    ));

    // --- Presentation ------------------------------------------------------

    $container->set(Chrome::class, static fn (Container $c): Chrome => new Chrome(
        $c->get(CategoryRepository::class),
        $config,
        $c->get(ClockInterface::class),
        $c->get(Csrf::class),
        $c->get(CartRepository::class),
    ));

    $container->set(AdminChrome::class, static fn (Container $c): AdminChrome => new AdminChrome(
        $c->get(View::class),
        $config,
        $c->get(Csrf::class),
        $c->get(AdminSession::class),
        $c->get(AuditTrail::class),
        $c->get(ClockInterface::class),
    ));

    // --- Paiement ---------------------------------------------------------
    //
    // 06-securite §7 : les cles vivent dans .env, et un controle au demarrage
    // interdit une cle de test en production comme une cle de production hors
    // production.

    $container->set(PaymentGateway::class, static fn (Container $c): PaymentGateway => new StripeCheckoutGateway(
        // Clés déjà résolues et validées au démarrage (voir StripeConfig). Sans
        // clé, on ne bascule PAS silencieusement sur un double : le site
        // annoncerait des paiements qui n'existent pas. La passerelle de test
        // n'est câblée que par les tests, explicitement. La clé reste une chaîne :
        // la passerelle ne construit son StripeClient qu'au premier paiement.
        $stripeConfig->secretKey,
        $stripeConfig->webhookSecret,
    ));

    $container->set(StripeEventRepository::class, static fn (Container $c): StripeEventRepository
        => new StripeEventRepository($c->get(PDO::class)));

    $container->set(StockRepository::class, static fn (Container $c): StockRepository
        => new StockRepository($c->get(PDO::class)));

    $container->set(OrderRepository::class, static fn (Container $c): OrderRepository
        => new OrderRepository($c->get(PDO::class)));

    $container->set(CartRepository::class, static fn (Container $c): CartRepository
        => new CartRepository($c->get(PDO::class)));

    $container->set(ProductRepository::class, static fn (Container $c): ProductRepository
        => new ProductRepository($c->get(PDO::class)));

    $container->set(VatRepository::class, static fn (Container $c): VatRepository
        => new VatRepository($c->get(PDO::class)));

    $container->set(ShippingRepository::class, static fn (Container $c): ShippingRepository
        => new ShippingRepository($c->get(PDO::class)));

    // Courrier sortant. En preprod, MailHog capture tout : ni chiffrement ni
    // authentification (MAIL_USERNAME et MAIL_ENCRYPTION vides).
    $container->set(MailerInterface::class, static fn (): MailerInterface => new SmtpMailer(
        $env->getOptional('MAIL_HOST', '127.0.0.1') ?? '127.0.0.1',
        (int) ($env->getOptional('MAIL_PORT', '1025') ?? '1025'),
        $env->getOptional('MAIL_USERNAME', '') ?? '',
        $env->getOptional('MAIL_PASSWORD', '') ?? '',
        $env->getOptional('MAIL_FROM_ADDRESS', 'commandes@cedrictaldu.com') ?? 'commandes@cedrictaldu.com',
        $env->getOptional('MAIL_FROM_NAME', 'Cédric Taldu') ?? 'Cédric Taldu',
        $env->getOptional('MAIL_ENCRYPTION', '') ?? '',
    ));

    $container->set(OrderMailer::class, static fn (Container $c): OrderMailer => new OrderMailer(
        $c->get(View::class),
        $c->get(MailerInterface::class),
        $env->getOptional('ARTIST_EMAIL', 'cedric@cedrictaldu.com') ?? 'cedric@cedrictaldu.com',
        $env->getOptional('ARTIST_NAME', 'Cédric Taldu') ?? 'Cédric Taldu',
    ));

    $container->set(ContactMailer::class, static fn (Container $c): ContactMailer => new ContactMailer(
        $c->get(View::class),
        $c->get(MailerInterface::class),
        $env->getOptional('ARTIST_EMAIL', 'cedric@cedrictaldu.com') ?? 'cedric@cedrictaldu.com',
        $env->getOptional('ARTIST_NAME', 'Cédric Taldu') ?? 'Cédric Taldu',
    ));

    $container->set(PaymentEventHandler::class, static fn (Container $c): PaymentEventHandler
        => new PaymentEventHandler(
            $c->get(PDO::class),
            $c->get(StripeEventRepository::class),
            $c->get(OrderRepository::class),
            $c->get(StockRepository::class),
            $c->get(LoggerInterface::class),
            $c->get(OrderMailer::class),
            // Lien de consultation signe (03-boutique §7) : reference + jeton,
            // construits cote serveur a partir de la commande.
            static fn (PersistedOrder $order): string => $c->get(UrlGenerator::class)->absolute(
                'checkout.confirmation',
                ['locale' => $order->locale->value, 'reference' => $order->reference],
            ) . '?t=' . $order->accessToken,
            $c->get(FulfillmentService::class),
        ));

    $container->set(CheckoutService::class, static fn (Container $c): CheckoutService => new CheckoutService(
        $c->get(PDO::class),
        $c->get(CartRepository::class),
        $c->get(OrderRepository::class),
        $c->get(StockRepository::class),
        $c->get(VatRepository::class),
        $c->get(ShippingRepository::class),
        $c->get(PaymentGateway::class),
        $c->get(UrlGenerator::class),
        $c->get(LoggerInterface::class),
    ));

    // --- Controleurs ------------------------------------------------------

    $container->set(HomeController::class, static fn (Container $c): HomeController => new HomeController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(SettingRepository::class),
        $c->get(ArtworkRepository::class),
        $c->get(MediaRepository::class),
        $c->get(PostRepository::class),
        $c->get(ClockInterface::class),
        $c->get(UrlGenerator::class),
        $c->get(StructuredData::class),
    ));

    $container->set(CategoryController::class, static fn (Container $c): CategoryController => new CategoryController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(CategoryRepository::class),
        $c->get(SeriesRepository::class),
        $c->get(ArtworkRepository::class),
        $c->get(MediaRepository::class),
        $c->get(UrlGenerator::class),
        $c->get(StructuredData::class),
    ));

    $container->set(ArtworkController::class, static fn (Container $c): ArtworkController => new ArtworkController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(ArtworkRepository::class),
        $c->get(CategoryRepository::class),
        $c->get(MediaRepository::class),
        $c->get(UrlGenerator::class),
        $c->get(PreviewToken::class),
        $c->get(ProductRepository::class),
        $c->get(StructuredData::class),
    ));

    // --- Controleurs d'administration --------------------------------------

    $container->set(AuthController::class, static fn (Container $c): AuthController => new AuthController(
        $c->get(AdminChrome::class),
        $c->get(AdminSession::class),
        $c->get(Authenticator::class),
        $c->get(UserRepository::class),
        $c->get(Validator::class),
        $c->get(RateLimiter::class),
        $c->get(Totp::class),
        $c->get(BackupCodes::class),
    ));

    $container->set(AccountController::class, static fn (Container $c): AccountController => new AccountController(
        $c->get(AdminChrome::class),
        $c->get(AdminSession::class),
        $c->get(UserRepository::class),
        $c->get(Totp::class),
        $c->get(BackupCodes::class),
        $c->get(RandomInterface::class),
        $config,
    ));

    $container->set(
        AdminArtworkController::class,
        static fn (Container $c): AdminArtworkController => new AdminArtworkController(
            $c->get(AdminChrome::class),
            $c->get(ArtworkAdminRepository::class),
            $c->get(CategoryAdminRepository::class),
            $c->get(SeriesAdminRepository::class),
            $c->get(TranslationInput::class),
            $c->get(PreviewToken::class),
            $c->get(UrlGenerator::class),
            $c->get(SlugHistory::class),
            $c->get(CoverUpload::class),
            $c->get(PrintAssetStore::class),
        ),
    );

    $container->set(
        AdminCategoryController::class,
        static fn (Container $c): AdminCategoryController => new AdminCategoryController(
            $c->get(AdminChrome::class),
            $c->get(CategoryAdminRepository::class),
            $c->get(SeriesAdminRepository::class),
            $c->get(TranslationInput::class),
            $c->get(SlugHistory::class),
            $c->get(CoverUpload::class),
        ),
    );

    $container->set(
        AdminPostController::class,
        static fn (Container $c): AdminPostController => new AdminPostController(
            $c->get(AdminChrome::class),
            $c->get(PostAdminRepository::class),
            $c->get(TranslationInput::class),
            $c->get(SlugHistory::class),
            $c->get(CoverUpload::class),
        ),
    );

    $container->set(
        AdminMessageController::class,
        static fn (Container $c): AdminMessageController => new AdminMessageController(
            $c->get(AdminChrome::class),
            $c->get(ContactMessageRepository::class),
            $c->get(ArtworkRepository::class),
        ),
    );

    $container->set(
        AdminPageController::class,
        static fn (Container $c): AdminPageController => new AdminPageController(
            $c->get(AdminChrome::class),
            $c->get(PageAdminRepository::class),
            $c->get(TranslationInput::class),
            $c->get(CoverUpload::class),
        ),
    );

    $container->set(MediaController::class, static fn (Container $c): MediaController => new MediaController(
        $c->get(AdminChrome::class),
        $c->get(MediaAdminRepository::class),
        $c->get(MediaStore::class),
        $c->get(Validator::class),
    ));

    $container->set(CheckoutController::class, static fn (Container $c): CheckoutController => new CheckoutController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(CartRepository::class),
        $c->get(OrderRepository::class),
        $c->get(CheckoutService::class),
        $c->get(UrlGenerator::class),
        $c->get(LoggerInterface::class),
        $c->get(ShippingRepository::class),
    ));

    $container->set(PageController::class, static fn (Container $c): PageController => new PageController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(PageRepository::class),
        $c->get(UrlGenerator::class),
    ));

    $container->set(StructuredData::class, static fn (): StructuredData => new StructuredData());

    $container->set(SlugHistory::class, static fn (Container $c): SlugHistory => new SlugHistory(
        $c->get(RedirectRepository::class),
        $c->get(UrlGenerator::class),
    ));

    $container->set(SitemapController::class, static fn (Container $c): SitemapController => new SitemapController(
        $c->get(UrlGenerator::class),
        $c->get(CategoryRepository::class),
        $c->get(ArtworkRepository::class),
        $c->get(PostRepository::class),
        $c->get(PageRepository::class),
        $c->get(ClockInterface::class),
    ));

    $container->set(BlogController::class, static fn (Container $c): BlogController => new BlogController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(PostRepository::class),
        $c->get(MediaRepository::class),
        $c->get(UrlGenerator::class),
        $c->get(ClockInterface::class),
        $c->get(StructuredData::class),
    ));

    $container->set(ContactController::class, static fn (Container $c): ContactController => new ContactController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(ContactMessageRepository::class),
        $c->get(ArtworkRepository::class),
        $c->get(SpamGuard::class),
        $c->get(FormTimestamp::class),
        $c->get(ContactMailer::class),
        $c->get(Validator::class),
        $c->get(UrlGenerator::class),
        $c->get(LoggerInterface::class),
        $c->get(ClockInterface::class),
        $config->securityPepper,
    ));

    $container->set(CartController::class, static fn (Container $c): CartController => new CartController(
        $c->get(View::class),
        $c->get(Chrome::class),
        $c->get(CartRepository::class),
        $c->get(CookieFactory::class),
        $c->get(UrlGenerator::class),
    ));

    $container->set(OrderAdminRepository::class, static fn (Container $c): OrderAdminRepository
        => new OrderAdminRepository($c->get(PDO::class)));

    $container->set(ProductAdminRepository::class, static fn (Container $c): ProductAdminRepository
        => new ProductAdminRepository($c->get(PDO::class)));

    $container->set(AdminProductController::class, static fn (Container $c): AdminProductController
        => new AdminProductController(
            $c->get(AdminChrome::class),
            $c->get(ProductAdminRepository::class),
        ));

    $container->set(AdminOrderController::class, static fn (Container $c): AdminOrderController
        => new AdminOrderController(
            $c->get(AdminChrome::class),
            $c->get(OrderAdminRepository::class),
            $c->get(OrderRepository::class),
            $c->get(OrderMailer::class),
            $c->get(UrlGenerator::class),
        ));

    $container->set(
        StripeWebhookController::class,
        static fn (Container $c): StripeWebhookController => new StripeWebhookController(
            $c->get(PaymentGateway::class),
            $c->get(PaymentEventHandler::class),
            $c->get(LoggerInterface::class),
        )
    );

    $container->set(DashboardController::class, static fn (Container $c): DashboardController => new DashboardController(
        $c->get(AdminChrome::class),
        $c->get(DashboardRepository::class),
        $c->get(AuditLogRepository::class),
    ));

    // --- Noyau ------------------------------------------------------------

    $container->set(Kernel::class, static fn (Container $c): Kernel => new Kernel(
        $c->get(Router::class),
        $c,
        $c->get(ErrorResponder::class),
        // Ordre impose par ARCHITECTURE §3. Maintenance et RateLimit s'inserent
        // ici quand la fonctionnalite qui les justifie arrive ; la limitation de
        // debit de la connexion est appliquee par le controleur, sa limite etant
        // propre a l'action (06-securite §6.3).
        [
            new SecurityHeaders($config, $c->get(RandomInterface::class)),
            new Locale($config),
            // Sur un 404 public, tente une redirection 301 d'un ancien slug avant
            // de rendre la page d'erreur (05-i18n-seo §5).
            new RedirectMiddleware($c->get(RedirectRepository::class)),
            new CsrfGuard($c->get(Csrf::class), $c->get(LoggerInterface::class)),
            new AuthGuard($c->get(AdminSession::class), $c->get(LoggerInterface::class)),
        ],
    ));

    return $container;
};
