# Architecture

## 1. Principe directeur

PHP custom, mais structuré : un **contrôleur frontal unique**, un **routeur**, des
**contrôleurs minces**, un **domaine métier pur et testable sans base de données**, et des
**dépôts (repositories)** qui sont les seuls à parler SQL.

La règle qui rend le TDD possible : *le domaine ne connaît ni PDO, ni `$_POST`, ni Stripe,
ni le temps qui passe*. Tout ce qui est I/O est derrière une interface, injectée.

```
HTTP → public/index.php → Kernel → Router → Middleware → Controller
                                                            │
                                    ┌───────────────────────┼──────────────────┐
                                    ▼                       ▼                  ▼
                              Repository (PDO)        Domain (pur)        Service (I/O)
                                    │                       │                  │
                                  MySQL                  (rien)      Stripe / SMTP / GD
                                    │
                                    ▼
                              View (templates PHP) → Response → HTTP
```

## 2. Arborescence

```
.
├── CLAUDE.md
├── composer.json / composer.lock
├── phpunit.xml
├── phpstan.neon
├── .env.example                  # documenté, commité — .env ne l'est jamais
├── .htaccess                     # redirection vers public/ si DocumentRoot non modifiable
│
├── public/                       # SEUL dossier exposé au web
│   ├── index.php                 # contrôleur frontal
│   ├── .htaccess                 # réécriture, en-têtes de sécurité, HTTPS
│   ├── robots.txt
│   ├── assets/
│   │   ├── css/site.css          # design system issu des maquettes
│   │   ├── js/                   # modules ES, sans build
│   │   │   ├── app.js            # point d'entrée
│   │   │   ├── nav.js            # menu burger + sous-menu Galerie
│   │   │   ├── prefetch.js       # préchargement au survol
│   │   │   ├── zoom.js           # zoom/pan sur l'œuvre
│   │   │   └── cart.js           # ajout panier en fetch, dégradable
│   │   └── fonts/                # Marcellus + Jost auto-hébergées (woff2)
│   └── media/                    # dérivés d'images publics, générés — jamais uploadés directement
│
├── src/
│   ├── CLAUDE.md
│   ├── Core/                     # socle réutilisable, zéro métier
│   │   ├── Kernel.php            # assemble requête → réponse (testable sans serveur)
│   │   ├── Container.php         # conteneur d'injection manuel, sans magie
│   │   ├── Router.php            # table de routes explicite, pas d'auto-découverte
│   │   ├── Request.php  Response.php  RedirectResponse.php
│   │   ├── Database.php          # fabrique PDO configurée strictement
│   │   ├── View.php              # rendu de templates PHP + échappement
│   │   ├── Session.php  Csrf.php  Cookie.php
│   │   ├── Config.php  Env.php
│   │   ├── Validator.php         # validation déclarative des entrées
│   │   ├── Clock.php             # ClockInterface + SystemClock + FrozenClock (tests)
│   │   ├── Logger.php
│   │   └── Exception/            # HttpException, NotFoundException, ...
│   │
│   ├── Domain/                   # PUR. Aucune I/O. 100 % testable en unitaire.
│   │   ├── Money.php             # entier de centimes, jamais de float
│   │   ├── Locale.php            # fr | en
│   │   ├── Slug.php
│   │   ├── Catalog/              # Artwork, Category, Series, ArtworkStatus
│   │   ├── Shop/                 # Cart, CartLine, LineKind, PricingPolicy, StockPolicy
│   │   ├── Order/                # Order, OrderLine, OrderStatus (machine à états), OrderReference
│   │   ├── Shipping/             # ShippingZone, ShippingCalculator, WeightBracket
│   │   └── Blog/                 # Post
│   │
│   ├── Repository/               # SEULE couche autorisée à écrire du SQL
│   │   ├── ArtworkRepository.php  CategoryRepository.php  SeriesRepository.php
│   │   ├── ProductRepository.php  VariantRepository.php
│   │   ├── CartRepository.php     OrderRepository.php
│   │   ├── PostRepository.php     PageRepository.php     MediaRepository.php
│   │   ├── UserRepository.php     SettingRepository.php
│   │   ├── ContactMessageRepository.php  StripeEventRepository.php
│   │   ├── RateLimitRepository.php       AuditLogRepository.php
│   │   └── Contract/             # interfaces, pour doubler en test
│   │
│   ├── Http/
│   │   ├── Middleware/           # SecurityHeaders, Locale, CsrfGuard, AuthGuard, RateLimit, Maintenance
│   │   ├── Controller/Front/     # HomeController, CategoryController, ArtworkController,
│   │   │                         # BlogController, PageController, ContactController,
│   │   │                         # CartController, CheckoutController, StripeWebhookController
│   │   └── Controller/Admin/     # AuthController, DashboardController, CategoryController,
│   │                             # ArtworkController, ProductController, MediaController,
│   │                             # OrderController, PostController, PageController,
│   │                             # MessageController, SettingController
│   │
│   ├── Service/                  # I/O, chacun derrière une interface
│   │   ├── Payment/              # PaymentGateway (interface), StripeCheckoutGateway, FakeGateway
│   │   ├── Mail/                 # MailerInterface, SmtpMailer, ArrayMailer (tests)
│   │   ├── Media/                # ImageProcessor (GD), DerivativeGenerator, UploadValidator
│   │   ├── Spam/                 # SpamGuard (honeypot + délai + heuristiques), RateLimiter
│   │   ├── I18n/                 # Translator, LocaleResolver, UrlGenerator
│   │   └── Seo/                  # SitemapBuilder, StructuredData
│   │
│   └── Support/                  # helpers globaux : e(), attr(), jsonAttr(), money(), asset()
│
├── templates/
│   ├── layouts/{public.php,admin.php,email.php}
│   ├── partials/{header.php,footer.php,nav.php,artwork-card.php,stipple.php,flash.php}
│   ├── front/{home,category,artwork,blog,post,page,contact,cart,checkout,error}.php
│   ├── admin/**
│   └── emails/{order-confirmation,order-shipped,contact-notification,contact-ack}.{fr,en}.php
│
├── storage/                      # HORS webroot. Interdit d'accès HTTP.
│   ├── uploads/                  # originaux uploadés, noms aléatoires
│   ├── cache/                    # rendus HTML de pages statiques, index de traduction
│   ├── logs/
│   └── sessions/
│
├── migrations/                   # 0001_init.sql, 0002_....sql — versionnées, jamais modifiées après merge
├── bin/                          # migrate.php, seed.php, create-admin.php, cron.php
├── docker/                       # environnement local et preprod Thor
│   ├── php/Dockerfile            # php:8.2-apache — parité avec le mutualisé de production
│   ├── apache/vhost.conf         # DocumentRoot -> /var/www/html/public
│   └── heimdall/customer.phracktale.com.location.conf   # extrait de vhost à reporter sur Heimdall
├── docker-compose.yml
├── docs/                         # specs (ce dossier)
├── maquette/                     # maquettes HTML de référence (lecture seule)
└── tests/
    ├── CLAUDE.md
    ├── Unit/                     # Domain + Core purs, sans base
    ├── Integration/              # Repositories contre une vraie base de test
    ├── Functional/               # Requête HTTP simulée → Response, via le Kernel
    ├── Security/                 # garde-fous automatisés (voir 06 et 07)
    ├── Support/                  # TestCase de base, factories, fixtures, doubles
    └── bootstrap.php
```

## 3. Flux d'une requête

1. `public/index.php` charge `vendor/autoload.php`, l'`.env`, construit le `Container` et
   le `Kernel`, puis émet la `Response`. Il ne contient **aucune logique**.
2. `Kernel::handle(Request): Response` — c'est le point d'entrée testé par les tests
   fonctionnels. Aucun test ne lance de serveur HTTP.
3. La chaîne de middlewares s'exécute dans cet ordre :
   `SecurityHeaders → Maintenance → Locale → Session → CsrfGuard → RateLimit → AuthGuard(admin)`.
4. Le `Router` résout la route à partir d'une **table explicite** (`config/routes.php`).
   Pas de découverte automatique : la liste des routes est lisible d'un coup d'œil, et un
   test de sécurité la parcourt pour vérifier que chaque route POST est protégée par CSRF.
5. Le contrôleur valide l'entrée avec `Validator`, appelle le domaine et/ou les dépôts,
   et retourne une `Response` construite par `View`.

## 4. Conventions

- **Namespace racine** `App\`, PSR-4 sur `src/`.
- `declare(strict_types=1);` en tête de **chaque** fichier PHP.
- Types de retour et de paramètres explicites partout ; PHPStan niveau 8 sans baseline.
- Les entités du domaine sont **immuables** (propriétés `readonly`, méthodes `with*()`).
- Argent : toujours `Money` (entier de centimes + devise). `float` interdit pour l'argent —
  un test de sécurité scanne le code à la recherche de calculs monétaires en flottant.
- Dates : `DateTimeImmutable` en UTC en base, converties en `Europe/Paris` à l'affichage.
  L'heure courante vient toujours de `ClockInterface`, jamais de `new DateTime()` direct.
- Un contrôleur ne dépasse pas ~120 lignes ; au-delà, la logique descend dans le domaine.
- Les dépôts retournent des objets du domaine ou des DTO de lecture, jamais des tableaux
  bruts de la base vers les templates.

## 5. Ce qui est volontairement écarté

- **Turbo / Hotwire** : apporte une couche d'interception de formulaires qui complique le
  tunnel de paiement et les redirections Stripe pour un gain faible ici. On implémente le
  préchargement au survol nous-mêmes (~60 lignes, voir `02-front-public.md` §5) et on
  utilise les *Speculation Rules* natives là où le navigateur les supporte.
- **ORM** : les dépôts avec du SQL explicite sont plus simples à auditer et à tester.
- **Système de conteneurs / file d'attente** : incompatible avec l'hébergement mutualisé.
- **Stockage du panier côté client** : le panier vit en base, référencé par un jeton en
  cookie. Le client ne peut pas influencer les prix.
