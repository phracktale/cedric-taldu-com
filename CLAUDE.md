# cedrictaldu.com — Site vitrine & boutique d'artiste

Site de l'artiste plasticien Cédric Taldu (Amiens) : présentation des œuvres, boutique
(originaux + reproductions), blog d'actualités, bilingue FR/EN.

## Stack et contraintes non négociables

| Élément | Choix | Contrainte |
| --- | --- | --- |
| Langage | PHP 8.2+ strict types | Pas de framework (Laravel/Symfony full stack interdits) |
| Base | MySQL 8 / MariaDB 10.6+, InnoDB, `utf8mb4_unicode_ci` | Accès **exclusivement** via PDO préparé |
| Hébergement | **Preprod** : Docker `php:8.2-apache` sur **Thor**, derrière Heimdall, sur `https://customer.phracktale.com/cedric-taldu`. **Prod** : mutualisé o2switch/OVH | Le site doit fonctionner **sous un préfixe de chemin comme à la racine**. Pas de daemon, pas de worker, pas de file d'attente |
| Développement | Windows **et** Linux (homelab), via Docker Compose | LF partout, casse des chemins significative, scripts POSIX |
| Composer | Utilisé en local, `vendor/` **commité** | `composer install` n'est pas garanti sur le serveur mutualisé |
| Templates | PHP natif + helpers d'échappement | Pas de Twig/Blade |
| Front JS | ES modules vanilla, **aucune étape de build** | Pas de npm en prod, pas de bundler |
| Paiement | Stripe Checkout hébergé, derrière `PaymentGateway` | Aucun numéro de carte ne transite par le site |
| Tests | PHPUnit 11 | **TDD strict : aucun code applicatif sans test rouge préalable** |

## Documents de référence — à lire avant de coder

| Fichier | Contenu |
| --- | --- |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Arborescence, couches, flux de requête, conventions de nommage |
| [docs/specs/00-perimetre-et-lexique.md](docs/specs/00-perimetre-et-lexique.md) | Vocabulaire métier, périmètre, hors-périmètre |
| [docs/specs/01-modele-de-donnees.md](docs/specs/01-modele-de-donnees.md) | Schéma SQL complet, invariants, machines à états |
| [docs/specs/02-front-public.md](docs/specs/02-front-public.md) | Pages publiques, modules, comportements JS |
| [docs/specs/03-boutique-paiement.md](docs/specs/03-boutique-paiement.md) | Panier, tunnel, Stripe, stock, expédition |
| [docs/specs/04-back-office.md](docs/specs/04-back-office.md) | CRUD, rôles, upload média, gestion commandes |
| [docs/specs/05-i18n-seo.md](docs/specs/05-i18n-seo.md) | Bilingue, URLs, hreflang, données structurées |
| [docs/specs/06-securite.md](docs/specs/06-securite.md) | **Garde-fous obligatoires** — SQLi, XSS, CSRF, spam, upload, RGPD |
| [docs/specs/07-tests-tdd.md](docs/specs/07-tests-tdd.md) | Protocole TDD, pyramide, tests de sécurité automatisés |
| [docs/specs/08-lots.md](docs/specs/08-lots.md) | Découpage en lots livrables et ordre d'implémentation |
| [docs/specs/09-environnements-deploiement.md](docs/specs/09-environnements-deploiement.md) | Homelab, Thor, préfixe de chemin, Docker, déploiement |

Les maquettes HTML de référence sont dans [maquette/](maquette/) : `index.html`,
`boutique-encres.html`, `boutique-fiche-oeuvre.html`. Elles définissent le design system
(palette, typographies, espacements, composants). **Le HTML produit doit reprendre
exactement leurs classes CSS et leur structure**, à trois exceptions près :
les gestionnaires `onclick` inline sont déplacés dans un module JS (CSP), les polices
Google sont auto-hébergées (RGPD + CSP), et les blocs `.dessin` factices sont remplacés
par de vraies balises `<picture>`.

## Règles de travail

1. **TDD non négociable.** Chaque unité de travail suit RED → GREEN → REFACTOR. On écrit
   le test qui échoue, on le voit échouer, on écrit le minimum de code pour le faire
   passer, puis on refactorise sous couverture. Voir [tests/CLAUDE.md](tests/CLAUDE.md).
2. **Un lot à la fois.** Ne pas démarrer le lot N+1 tant que la suite complète du lot N
   n'est pas verte et que la revue de sécurité du lot n'est pas passée.
3. **La sécurité est une exigence testée, pas une intention.** Toute règle de
   `06-securite.md` a un test qui la vérifie. Si une règle n'a pas de test, elle n'est pas
   considérée comme implémentée.
4. **Aucune URL en dur.** Le site tourne sous `/cedric-taldu` en preprod et à la racine en
   prod : toute URL passe par `UrlGenerator` / `asset()`. Voir `09-environnements-deploiement.md` §3.
5. **Aucune dépendance nouvelle sans validation explicite.** Les seules dépendances de
   production autorisées sont `stripe/stripe-php` et `phpmailer/phpmailer`.
6. **Rien de secret dans le dépôt.** Clés Stripe, SMTP, sel de hachage : `.env` uniquement,
   hors webroot, jamais commité. `.env.example` documente chaque variable.
7. **Prix, stock, statuts : autorité serveur exclusive.** Aucune valeur monétaire ou de
   disponibilité venant du client n'est jamais utilisée pour calculer une commande.
8. **Français dans le code métier.** Les noms de tables et de colonnes sont en anglais ;
   les libellés, contenus, commentaires métier et messages utilisateur sont en français.

## Commandes

```bash
docker compose up -d                    # environnement local complet (app + MySQL + MailHog)
docker compose exec app composer test   # suite complète PHPUnit
composer install                        # dépendances (en local, vendor/ est commité)
composer test                           # suite complète
composer test -- --testsuite unit       # une suite : unit | integration | functional | security
composer lint                           # php -l récursif + PHP_CodeSniffer PSR-12
composer stan                           # PHPStan niveau 8 sur src/
php bin/migrate.php                     # applique les migrations en attente
php bin/migrate.php --status            # état des migrations
php bin/seed.php --demo                 # jeu de données de démonstration (jamais en prod)
php bin/create-admin.php                # crée un compte administrateur
```

## Git

- **Jamais de branche `claude/*`.** Les branches suivent la convention classique, choisie
  selon la nature du travail :

  | Préfixe | Usage |
  | --- | --- |
  | `feature/` | nouvelle fonctionnalité (`feature/fiche-oeuvre`, `feature/tunnel-stripe`) |
  | `bugfix/` | correction sur une fonctionnalité en cours de développement |
  | `hotfix/` | correction urgente sur ce qui est en production |
  | `refactor/` | restructuration sans changement de comportement |
  | `chore/` | outillage, dépendances, configuration |
  | `docs/` | documentation et specs seules |

- Une branche par lot fonctionnel (voir `08-lots.md`), fusionnée dans `main` une fois la
  suite verte et la revue de sécurité passée.
- Commits en français, préfixés par le type et la portée :
  `test(panier): une œuvre vendue ne peut plus être ajoutée`, puis
  `feat(panier): retrait automatique des lignes indisponibles`.
- Un commit `feat` sans le commit `test` correspondant dans la même branche est un défaut
  de process (voir `tests/CLAUDE.md`).
- On ne commite jamais sur `main` directement, ni `.env`, ni `storage/`, ni de données
  clients.

## Environnements

| Env | URL | Détail |
| --- | --- | --- |
| local | `http://localhost:18120/cedric-taldu` | Docker Compose, `APP_BASE_PATH=/cedric-taldu` |
| preprod | `https://customer.phracktale.com/cedric-taldu` | Thor `192.168.1.36:18120`, TLS terminé par Heimdall, `X-Forwarded-Prefix` |
| prod | `https://cedrictaldu.com` `@decision` | Mutualisé, `APP_BASE_PATH=""` |

## Pièges connus de cet environnement

- **Préfixe de chemin** : la preprod sert le site sous `/cedric-taldu`. Un chemin absolu
  écrit en dur casse la preprod sans casser la prod — donc passe les tests locaux et se
  découvre en ligne. `BasePathTest` rejoue toutes les routes avec un préfixe non vide.
- **Cookies partagés de domaine** : `customer.phracktale.com` héberge aussi ENERIA. Les
  cookies portent `path=/cedric-taldu` et un nom préfixé (`ct_session`, `ct_cart`).
- **Dev Windows + Linux** : la casse des noms de fichiers est significative sur Thor et en
  prod. Fins de ligne LF imposées par `.gitattributes`.
- **`vendor/` est commité** : après tout ajout de dépendance, commiter `vendor/` et
  `composer.lock` ensemble.
- **Pas de worker, cron non garanti** : les tâches périodiques (purge RGPD, libération des
  réservations, purge des paniers) sont exposées par un endpoint protégé par jeton, et
  doivent être idempotentes.
- **`DocumentRoot` = `public/`** : c'est le cas dans le conteneur. Sur un mutualisé qui ne
  le permet pas, le `.htaccess` racine réécrit vers `public/`, mais `storage/`, `src/`,
  `.env` et `vendor/` doivent rester inaccessibles dans tous les cas. Test dédié.
- **Preprod jamais indexée** : `X-Robots-Tag: noindex` dès que `APP_ENV != prod`, clés
  Stripe de test uniquement, e-mails capturés par MailHog.
