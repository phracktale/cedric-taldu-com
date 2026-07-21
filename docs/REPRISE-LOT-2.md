# Prompt de reprise — lot 2

> À coller tel quel dans une session neuve.

```
Tu poursuis la construction du site vitrine et boutique de Cédric Taldu.
Les lots 0 et 1 sont terminés, fusionnés dans `main` et déployés en
préproduction. Tu attaques le lot 2.

## Lis d'abord, intégralement

1. CLAUDE.md (racine) — stack, contraintes, règles de travail, Git, environnements
2. src/CLAUDE.md et tests/CLAUDE.md — conventions de code et protocole TDD
3. docs/ARCHITECTURE.md
4. docs/specs/01-modele-de-donnees.md — schéma et invariants
5. docs/specs/04-back-office.md — le périmètre du lot 2
6. docs/specs/06-securite.md — §4 authentification et §5 téléversement, qui sont
   le cœur de ce lot
7. docs/specs/07-tests-tdd.md et docs/specs/08-lots.md
8. docs/REPRISE-LOT-2.md (ce fichier) — l'état réel du dépôt

Puis parcours le code existant : src/Core/, src/Domain/, src/Repository/,
src/Http/, templates/, tests/Support/.

## Ce que tu construis

Le **lot 2 — Back-office catalogue**, tel que défini dans docs/specs/08-lots.md.
Rien de plus. Critère de fin : l'artiste peut créer une rubrique et une œuvre de
bout en bout sans toucher au code, et `UploadTest` passe intégralement.

## Comment tu travailles

TDD strict, sans exception : test rouge, tu le montres, code minimal, vert,
refactorisation. Ordre imposé par 07-tests-tdd §6 — unitaire du domaine, puis
intégration de la persistance, puis fonctionnel du parcours, puis sécurité.

Branche `feature/lot-2-back-office-catalogue`. Jamais de commit direct sur
`main`. Commits en français, `test(...)` AVANT le `feat(...)` correspondant.

**Déploiement.** L'utilisateur veut vérifier chaque lot en conditions réelles :
à la fin du lot, tu fusionnes dans `main`, tu pousses, et tu déploies sur Thor
(voir « Déploiement » plus bas). Tu ne déploies pas en cours de lot.

Quand une spec est ambiguë ou qu'un point `@decision` te bloque, tu t'arrêtes et
tu poses la question, avec ta recommandation et son motif.
```

---

## État réel du dépôt

### Ce qui tourne

| Environnement | URL | Comment |
| --- | --- | --- |
| **local** | `http://localhost:8000/cedric-taldu` | `composer serve` — serveur web interne de PHP, **aucun Docker en local** |
| **préprod** | `https://customer.phracktale.com/cedric-taldu` | Thor `192.168.1.36`, Docker, derrière Heimdall |

**Docker est réservé à Thor.** En local, `bin/router.php` reproduit le peu de
réécriture des `.htaccess`.

### Base de données

Il n'y a **pas de MySQL local**. Les tests d'intégration et fonctionnels
s'adressent à la base `cedrictaldu_test` **de Thor**, par le LAN
(`192.168.1.36:13306`), avec deux comptes dédiés créés le 2026-07-21 :

| Compte | Droits | Rôle |
| --- | --- | --- |
| `cedrictaldu_dev` | schéma sur `cedrictaldu_test` | migrations des tests |
| `cedrictaldu_dev_app` | SELECT/INSERT/UPDATE/DELETE sur `cedrictaldu_test` | pendant du compte applicatif |

Ni l'un ni l'autre ne touche la base de préproduction. Cette séparation n'est pas
cosmétique : elle fait vivre le test « une injection SQL réussie ne peut pas
supprimer une table ».

**Piège connu** : `.env` local pointe `DB_NAME` sur `cedrictaldu_test`. Lancer
`php bin/seed.php --demo` en local remplit donc la base **de test**, et les tests
qui comptent des lignes échouent ensuite. Vider les tables du catalogue après.
À améliorer : une base locale distincte de la base de test.

### Commandes

```bash
composer serve   # http://localhost:8000/cedric-taldu
composer test    # 830 tests, ~10 s
composer stan    # PHPStan niveau 8, sans baseline
composer lint    # php -l récursif + PSR-12 (avertissements non bloquants)

php bin/migrate.php [--status] [--env=test]
php bin/seed.php --demo [--force]
```

### Déploiement

```bash
ssh phracktale@thor
cd /home/phracktale/apps/cedric-taldu
git pull origin main
docker compose exec -T app composer install --no-dev
docker compose exec -T app php bin/migrate.php
docker compose exec -T app php bin/seed.php --demo --force   # préprod seulement
```

Le vhost Heimdall (`location /cedric-taldu/`) est **déjà posé** et la table de
routage des ports du homelab est à jour — sauf le commit, laissé à
l'utilisateur (le dépôt HOMELAB est sur une branche avec du travail en cours).

Heimdall **retire** le préfixe avant de transmettre (`proxy_pass …:18120/`) : le
conteneur reçoit `/fr/` alors que `APP_BASE_PATH` vaut `/cedric-taldu`. Deux
tests de `BasePathTest` verrouillent cette topologie.

---

## Ce qui existe

### `src/Core/` — socle (lot 0)

`Env`, `Config`, `Request`, `Response`, `RedirectResponse`, `Route`, `Router`,
`RouteMatch`, `Container`, `Kernel`, `ErrorResponder`, `FailSafeResponse`,
`View`, `Cookie`, `CookieFactory`, `SessionInterface` / `PhpSession`, `Csrf`,
`RandomInterface` / `SecureRandom`, `ClockInterface` / `SystemClock`,
`LoggerInterface` / `LogLevel` / `FileLogger`, `Validator` / `Rule`, `Database`,
`Migrator`, plus 13 exceptions.

Points à connaître avant de toucher au socle :

- `Request` est le **seul** endroit qui lit les superglobales. `SuperglobalTest`
  le vérifie sur le code source.
- `View` n'emploie **ni `extract`, ni variables variables**. Exactement quatre
  variables entrent dans la portée d'un gabarit : `$data`, `$url`, `$content`,
  `$partial`.
- La session démarre **paresseusement** : une lecture anonyme ne pose aucun
  cookie.
- `public/index.php` enveloppe tout l'amorçage dans un `try/catch` qui rend une
  `FailSafeResponse`. `ErrorLeakTest` lit le source pour empêcher son retrait.

### `src/Domain/` — pur, sans I/O (lot 1)

`Locale`, `Slug`, `Money`, `Translations<T>`, et sous `Catalog/` :
`ArtworkStatus`, `Dimensions`, `Media`, `MediaTranslation`, `Category`,
`CategoryTranslation`, `Series`, `SeriesTranslation`, `Artwork`,
`ArtworkTranslation`.

`Translations<T>` porte le repli linguistique de 05-i18n-seo §3 une seule fois,
pour toutes les entités.

### `src/Repository/`

`CategoryRepository`, `SeriesRepository`, `ArtworkRepository`,
`MediaRepository`, `SettingRepository`. Tous chargent **par lot**, jamais de
N+1. Il n'y a **pas encore** de `Contract/` : aucun consommateur n'a eu besoin
de les doubler, les tests fonctionnels passant par la vraie base. À introduire
au lot 3 pour `PaymentGateway`.

Trois pièges MySQL rencontrés, documentés dans le code :

1. `LIMIT` est refusé dans une sous-requête `IN` — la sélection se fait en deux
   temps, identifiants ordonnés puis chargement.
2. `LIMIT` et `OFFSET` doivent être liés en **entier** (`PDO::PARAM_INT`) :
   sans émulation des préparations, PDO les envoie entre guillemets.
3. Un nom de paramètre ne peut pas être lié **deux fois** hors émulation.

### Front public

`Chrome` (contexte commun), `HomeController`, `CategoryController`,
`ArtworkController`, `config/routes.php` (routes bilingues à segments traduits),
`templates/` (mise en page, en-tête à menu Galerie dynamique, pied de page,
`picture`, `artwork-card`, trois pages), `public/assets/css/site.css` (extrait
des maquettes), quatre modules JS sans build.

### Tests — 830, tous verts

| Suite | Nombre | Durée |
| --- | --- | --- |
| `unit` | 497 | < 1 s |
| `integration` | 108 | ~5 s |
| `functional` | 57 | ~3 s |
| `security` | 168 | ~2 s |

Socles : `FunctionalTestCase` (traverse `Kernel::handle`, connexion partagée avec
les fixtures, transaction annulée), `DatabaseTestCase`, `SchemaTestCase`,
`SourceScanner`. Factories : `CategoryFactory`, `SeriesFactory`, `MediaFactory`,
`ArtworkFactory`. Doubles : `FrozenClock`, `ArraySession`, `SequenceRandom`,
`RecordingLogger`, contrôleurs factices.

**Une seule connexion PDO pour tout le processus de test** : une connexion par
test se disputait les verrous de métadonnées, et `lock_wait_timeout` valant un
an par défaut, la suite ne plantait pas — elle se figeait. 122 s → 10 s.

### Suite `security` — 11 fichiers

`EscapingTest`, `SqlLocationTest`, `SuperglobalTest`, `HeadersTest`,
`BasePathTest`, `ErrorLeakTest`, `ExposureTest`, `SpoofedHeaderTest`,
`HttpsTest`, `XssTest`, `SqlInjectionTest`.

Les scanners passent par le **tokeniseur de PHP**, pas par une recherche de
texte : un commentaire qui explique pourquoi `mt_rand` est interdit ne doit pas
compter comme un usage de `mt_rand`.

---

## Écarts assumés avec les specs

| Écart | Motif |
| --- | --- |
| `vendor/` non suivi | Ne contient que l'outillage de dev. Règle rétablie au lot 3, avec `stripe/stripe-php`. |
| Pas de `url()` ni `asset()` en fonctions globales | Dépendent du préfixe résolu **par la requête** ; une fonction globale aurait exigé un singleton, interdit. Les gabarits reçoivent `$url`. |
| `Media::FORMATS` = `['webp','jpg']`, sans AVIF | La GD du conteneur n'a pas AVIF. Annoncer une `<source>` sans fichier afficherait une image cassée. **`libavif-dev` a été ajouté au Dockerfile** : au lot 2, reconstruire l'image, remettre `avif` en tête de `FORMATS` et régénérer. |
| SQL toléré dans `Core/Migrator` | Il construit le schéma dont les dépôts dépendent. Exception nommée dans `SqlLocationTest`. |
| PSR-12 camelCase désactivé sur `tests/` | `tests/CLAUDE.md` impose des noms de test en phrase française. |
| Avertissements de longueur de ligne non bloquants | PSR-12 ne fixe pas de limite dure ; les forcer couperait des attributs HTML. |
| Modules « Actus » et « Atelier » de l'accueil lus dans `settings` | `posts` et la page `about` arrivent au lot 4. |
| `PhpSession::start()` non couvert par un test | Seule `options()` l'est. **À combler au lot 2**, avec l'authentification. |

---

## Ce que le lot 2 doit faire

Le périmètre est dans `08-lots.md` et `04-back-office.md`. Points de vigilance :

1. **`UploadTest` est le test le plus exigeant du projet.** Toutes les fixtures
   de 07-tests-tdd §4 sont à créer : JPEG/PNG/WebP valides, **PHP renommé en
   `.jpg`**, **polyglotte GIF/PHP**, SVG, JPEG avec EXIF GPS, bombe de
   décompression 50 000 × 50 000, fichier vide, fichier tronqué.
2. **Le ré-encodage GD n'est pas optionnel** : il détruit toute charge embarquée
   et supprime les métadonnées, géolocalisation comprise (06-securite §5.4).
3. **Le générateur de dérivés existe déjà en germe** dans
   `src/Service/Media/PlaceholderGenerator.php` : même schéma de nommage, mêmes
   largeurs. Le vrai pipeline le remplacera pour les vraies images.
4. **`Media::WIDTHS` et `Media::FORMATS` font autorité** sur ce qui est produit.
5. **2FA TOTP** : aucune dépendance autorisée au-delà de `stripe/stripe-php` et
   `phpmailer/phpmailer`. TOTP (RFC 6238) tient en une cinquantaine de lignes
   avec `hash_hmac` — à écrire, pas à installer.
6. **`AuthGuard`** s'insère dans la chaîne de `config/services.php`, après
   `CsrfGuard`, dans l'ordre d'`ARCHITECTURE.md` §3.
7. Les tables `users` et `audit_log` **existent déjà** (migration `0001_init`).

## Questions ouvertes

- **Images réelles des œuvres** : toujours absentes. La préprod montre des
  placeholders engendrés à la trame du site.
- **Textes anglais** : seules les rubriques ont une traduction ; les œuvres et
  les réglages sont en français, et le repli s'applique.
- `@decision` en attente, bloquants à partir du lot 3 : TVA (traitement des
  tirages rehaussés), grille tarifaire de port, délai de rétractation,
  numérotation des tirages, mentions légales, domaine de production.
