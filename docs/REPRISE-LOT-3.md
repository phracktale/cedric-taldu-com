# Prompt de reprise — lot 3

> À coller tel quel dans une session neuve.

```
Tu poursuis la construction du site vitrine et boutique de Cédric Taldu.
Les lots 0, 1 et 2 sont terminés, fusionnés dans `main` et déployés en
préproduction. Tu attaques le lot 3 — Boutique et paiement.

## Lis d'abord, intégralement

1. CLAUDE.md (racine) — stack, contraintes, règles de travail, Git, environnements
2. src/CLAUDE.md et tests/CLAUDE.md — conventions de code et protocole TDD
3. docs/ARCHITECTURE.md
4. docs/specs/01-modele-de-donnees.md — schéma et invariants
5. docs/specs/03-boutique-paiement.md — le périmètre du lot 3
6. docs/specs/06-securite.md — §7 paiement, qui est le cœur de ce lot
7. docs/specs/07-tests-tdd.md et docs/specs/08-lots.md
8. docs/REPRISE-LOT-3.md (ce fichier) — l'état réel du dépôt

Puis parcours le code existant : src/Core/, src/Domain/, src/Repository/,
src/Service/, src/Http/, templates/, tests/Support/.

## Commence par poser les questions

Cinq `@decision` sont devenus bloquants et sont listés en tête de
docs/REPRISE-LOT-3.md : TVA des tirages rehaussés, grille tarifaire de port,
délai de rétractation, numérotation des tirages, régime de TVA au démarrage.

Tu ne peux pas écrire `VatPolicy` ni `ShippingCalculator` sans eux, et le régime
de TVA initial est **définitif** pour toutes les commandes de la période
(01-modele §7.7). Pose ces questions AVANT d'ouvrir la branche, avec ta
recommandation et son motif pour chacune.

## Ce que tu construis

Le **lot 3 — Boutique et paiement**, tel que défini dans docs/specs/08-lots.md.
Rien de plus. Critère de fin : un achat d'original et un achat de reproduction
aboutissent en mode test Stripe, avec décrément de stock, e-mails et
impossibilité de double vente prouvée par test.

C'est le lot où `vendor/` redevient suivi : `stripe/stripe-php` et
`phpmailer/phpmailer` arrivent, et `composer install` n'est pas garanti sur
l'hébergement mutualisé. Liste blanche, sans les dépendances de développement,
commité avec `composer.lock` (CLAUDE.md, « Pièges connus »).

## Comment tu travailles

TDD strict, sans exception : test rouge, tu le montres, code minimal, vert,
refactorisation. Ordre imposé par 07-tests-tdd §6 — unitaire du domaine, puis
intégration de la persistance, puis fonctionnel du parcours, puis sécurité.

Branche `feature/lot-3-boutique-paiement`. Jamais de commit direct sur `main`.
Commits en français, `test(...)` AVANT le `feat(...)` correspondant.

**Un test qui vérifie qu'une valeur est ÉCRITE ne vérifie pas que la règle est
APPLIQUÉE.** Le lot 2 a livré une faille de force brute sur le second facteur
pour cette exact raison : le compteur de verrouillage était écrit à chaque échec
et jamais relu, et le test se contentait de constater l'écriture. Ce lot manipule
du stock et de l'argent — la question à se poser sur chaque invariant est « qui
le LIT ? ».

**Déploiement.** À la fin du lot, tu fusionnes dans `main`, tu pousses, et tu
déploies sur Thor. Tu ne déploies pas en cours de lot.

Déployer ne suffit pas : **vérifie en conditions réelles**. Le lot 2 a été
déclaré terminé alors que le back-office était inutilisable en préproduction,
faute d'avoir tenté une vraie connexion après le déploiement. La procédure et sa
vérification sont dans docs/REPRISE-LOT-3.md §Déploiement.

Quand une spec est ambiguë ou qu'un point `@decision` te bloque, tu t'arrêtes et
tu poses la question, avec ta recommandation et son motif.
```

---

## Décisions — TOUTES TRANCHÉES le 2026-07-21

Les cinq `@decision` bloquants, plus un sixième découvert en écrivant `VatPolicy`.
Le détail et les motifs sont dans
[specs/00-perimetre-et-lexique.md](specs/00-perimetre-et-lexique.md) §5 bis.

| Point | Décision |
| --- | --- |
| **Régime de TVA au démarrage** | `exempt_293b`, `taxable_from` nulle. **Définitif** pour la période. |
| **TVA des tirages rehaussés** | `standard_goods`, 20 % |
| **Numérotation des tirages** | Au paiement, dans le webhook, sous verrou de ligne |
| **Rétractation** | 14 jours, retour aux frais du client |
| **Grille de port** | Forfait par zone : FR 9 €, UE 20 €, Monde 35 €. Franco FR 300 €, UE 800 €. Tranche unique à 10 kg, emballage 250 g |
| **TVA du port** (découvert en cours) | Deux colonnes dédiées sur `order_items`. Les six invariants de 01-modele §7.6 sont contradictoires sans elles |

---

## Où en est le lot 3 — branche `feature/lot-3-boutique-paiement`

**33 commits, suite complète verte à chaque commit** : ~1 715 tests, PHPStan 8 sans
erreur, PSR-12 sans erreur. **Rien n'est déployé** — le lot n'est pas fini.

### Fait

| Couche | Contenu |
| --- | --- |
| `Domain/Money` | `plus`, `minus`, `times`, `sum`, `isAtLeast`, `excludingVat` (arrondi **bancaire**), `allocate` (ventilation au prorata) |
| `Domain/Order/` | `VatCategory`, `VatMode`, `VatRegime`, `VatRate`, `VatRateTable`, `VatPolicy`, `TaxableLine`, `LineVat`, `VatBreakdown`, `OrderStatus`, `OrderReference` |
| `Domain/Shipping/` | `ShippingMethod`, `WeightBracket`, `ShippingZone`, `ShippingZones`, `ShippingQuote`, `ShippingCalculator` |
| `Domain/Shop/` | `Cart`, `CartLine`, `LineKind`, `PurchasableItem`, `ItemCatalogue`, `StockPolicy`, `PricingPolicy`, `CartValuation`, `ValuedLine`, `CartNotice`, `CartNoticeReason` |
| `Domain/Catalog/ArtworkStatus` | Machine à états complétée + `effectiveAt()` (expiration de réservation à la lecture) |
| `migrations/0005_boutique.sql` | Les onze tables, amorces TVA / port / réglages |
| `Domain/Order/` (suite) | `Address` (refus des CR/LF à la construction), `OrderDraft`, `OrderLineDraft` |
| `Repository/` | `StockRepository`, `CartRepository`, `OrderRepository`, `StripeEventRepository`, `VatRepository`, `ShippingRepository`, plus `PersistedOrder`, `PersistedOrderLine`, `EventClaim` |
| `Service/Payment/` | `PaymentGateway`, `WebhookSignature`, `FakeGateway`, `StripeCheckoutGateway`, `CheckoutSession`, `WebhookEvent` |
| `vendor/` | Suivi en liste blanche, avec `stripe/stripe-php` v21 et `phpmailer` v7 |
| Tests | `SchemaBoutiqueTest`, `StockRepositoryTest` (dont **3 de concurrence à deux connexions PDO**), `CartRepositoryTest`, `OrderRepositoryTest`, `StripeEventRepositoryTest`, `VatRepositoryTest`, `ShippingRepositoryTest`, `VendorTest`, `OrderDraftTest`, `FakeGatewayTest`, `StripeKeyEnvironmentTest` |

### Reste à faire, dans l'ordre

1. **`ProductRepository`** : reproductions publiées d'une œuvre, pour la fiche.
2. **`Service/Mail/`** : `MailerInterface`, `SmtpMailer` (PHPMailer), `ArrayMailer`,
   gabarits `emails/order-confirmation.{fr,en}.php` et `order-shipped`.
3. **Front** : zone reproductions de la fiche œuvre, `CartController`,
   `CheckoutController`, page de confirmation, `cart.js`, gabarits.
4. **Webhook** `POST /webhooks/stripe` — signé, idempotent, transactionnel. **Toutes les
   briques existent** : `StripeEventRepository::claim`, les quatre écritures de
   `StockRepository`, `OrderRepository::transitionTo` / `flagAnomaly` /
   `setEditionNumber`. Il reste à les orchestrer dans une transaction.
5. **Back-office** : reproductions, variantes, commandes, expédition, export CSV.
6. **Sécurité** : `PriceIntegrityTest`, `WebhookTest`, `OrderTransitionTest`,
   `MoneyTypeTest`, `TokenTest`.
7. **Fusion, déploiement Thor, vérification en conditions réelles** (§Déploiement).
   Ajouter au préalable les variables Stripe et SMTP à `.env.example`.

### Six pièges rencontrés, à ne pas rouvrir

1. **La clé d'unicité de `cart_items` telle que 01-modele §5 la définit ne protège
   rien.** `(cart_id, kind, artwork_id, variant_id)` : MySQL ne tient jamais deux `NULL`
   pour égaux dans un index unique, et une ligne `original` a `variant_id` à `NULL`. La
   même œuvre s'ajoutait deux fois. Corrigé par une colonne générée
   `target_id = COALESCE(artwork_id, variant_id)`, en **VIRTUAL** et non `STORED` :
   MySQL refuse `ON DELETE CASCADE` sur la colonne de base d'une colonne générée
   stockée (erreur 1215).
2. **Un test d'intégration qui écrit hors transaction doit nettoyer ce qu'il crée,
   rubriques comprises.** Trois rubriques orphelines laissées par les tests de
   concurrence faisaient échouer dix-neuf tests fonctionnels à l'autre bout de la suite,
   sans aucun rapport apparent.
3. **L'arrondi du HT est bancaire**, pas commercial (07-tests-tdd §2.1). 999 centimes
   TTC à 20 % donnent 832 et non 833. Les attendus écrits d'instinct sont faux.
4. **Un marqueur nommé ne peut pas apparaître deux fois dans une requête.**
   `EMULATE_PREPARES` est à `false` : `VALUES (…, :now, :now)` échoue en
   `SQLSTATE[HY093]`. Il faut deux marqueurs distincts pour un même instant.
5. **`SqlLocationTest` interdit de concaténer une variable à une chaîne SQL, sans
   exception** — y compris quand la variable ne contient qu'une liste de marqueurs
   `IN (?, ?)`. La convention du dépôt est celle d'`ArtworkRepository` : le SQL vit dans
   une constante de classe et la liste passe par un `%s` de `sprintf`.
6. **Les cartes d'autoload commitées doivent venir de `composer dump:prod`.**
   `vendor/composer/autoload_files.php` fait un `require` **à chaud** : un dump engendré
   avec les dépendances de développement y inscrit PHPUnit, PHPStan et deep-copy, et
   ferait échouer la toute première requête en production sur un fichier absent. En
   local, `composer install` réintroduit ces entrées — la copie de travail montre donc
   `vendor/composer/*.php` modifiés en permanence, **c'est normal**. `VendorTest` vérifie
   le contenu **commité**, pas la copie de travail.

---

## État réel du dépôt

### Ce qui tourne

| Environnement | URL | Comment |
| --- | --- | --- |
| **local** | `http://localhost:8000/cedric-taldu` | `composer serve` — serveur web interne de PHP, **aucun Docker en local** |
| **préprod** | `https://customer.phracktale.com/cedric-taldu` | Thor `192.168.1.36`, Docker, derrière Heimdall |
| **back-office** | `…/admin` | Compte créé par `php bin/create-admin.php` |

### Commandes

```bash
composer serve   # http://localhost:8000/cedric-taldu — limites d'upload relevées à 25 Mo
composer test    # 1320 tests, ~60 s
composer stan    # PHPStan niveau 8, sans baseline
composer lint    # php -l récursif + PSR-12 (longueur de ligne non bloquante)

php bin/migrate.php [--status] [--env=test]
php bin/seed.php --demo [--force]
php bin/create-admin.php --email=<adresse> --nom=<nom> [--role=admin|editor]
```

### Base de données

Inchangé depuis le lot 2 : pas de MySQL local, les suites `integration` et
`functional` s'adressent à `cedrictaldu_test` **sur Thor** par le LAN
(`192.168.1.36:13306`).

**Piège toujours ouvert** : `.env` local pointe `DB_NAME` sur `cedrictaldu_test`.
`php bin/seed.php --demo` **et** `php bin/create-admin.php` écrivent donc dans la
base de test, et les tests qui comptent des lignes échouent ensuite. Vider après
usage. À améliorer : une base locale distincte.

**Nouveauté du lot 2** : `DatabaseTestCase` force un passage du migrateur au
démarrage du processus de test. Une migration nouvellement ajoutée est donc
appliquée sans intervention — avant, il fallait détruire la base à la main.

### Déploiement

```bash
ssh phracktale@thor
cd /home/phracktale/apps/cedric-taldu
git pull origin main
docker compose build app                     # si docker/ a changé — voir ci-dessous
docker compose up -d --force-recreate app
docker compose exec -T app php bin/migrate.php
docker compose exec -T app php bin/seed.php --demo --force   # préprod seulement
```

**`docker compose build` n'est plus facultatif quand `docker/` change.** L'image
porte désormais un point d'entrée (`docker/php/entrypoint.sh`) sans lequel le
conteneur ne peut rien écrire. Un `git pull` seul mettrait le code à jour en
laissant tourner l'ancienne image.

Le vhost Heimdall (`location /cedric-taldu/`) est **déjà posé**, et Heimdall
**retire** le préfixe avant de transmettre (`proxy_pass …:18120/`) : le conteneur
reçoit `/fr/` alors que `APP_BASE_PATH` vaut `/cedric-taldu`. Deux tests de
`BasePathTest` verrouillent cette topologie.

**Vérification après déploiement** — la session doit persister, sinon rien ne
marche en back-office :

```bash
URL=https://customer.phracktale.com/cedric-taldu
curl -sS -c jar -o p1 "$URL/admin/connexion"
curl -sS -b jar -o p2 "$URL/admin/connexion"
grep -oE 'value="[a-f0-9]{64}"' p1 p2   # deux valeurs IDENTIQUES = session OK
```

### Comptes d'administration

Un compte existe en préproduction (`phracktale@gmail.com`, rôle `admin`). Pour en
créer un autre :

```bash
docker compose exec -T app php bin/create-admin.php \
    --email=<adresse> --nom="<nom affiché>" [--role=admin|editor]
```

Le mot de passe est engendré et **affiché une seule fois** : il n'est stocké
qu'en empreinte Argon2id. La 2FA s'active ensuite depuis `/admin/compte/2fa`.

---

## Ce que le lot 2 a ajouté

### Migrations

- `0003_back_office.sql` — `user_backup_codes`, `category_translations.method_text`,
  `media.original_name`.
- `0004_totp_replay.sql` — `users.totp_last_counter` (anti-rejeu RFC 6238 §5.2).

### `src/Domain/Admin/`

`Role` (admin | editor, droits par `match` exhaustif), `AdminUser` (immuable,
verrouillage après cinq échecs), `SessionPolicy` + `SessionStatus` (inactivité
30 min, absolu 12 h, empreinte /24 en IPv4 et /64 en IPv6).

### `src/Service/Auth/`

`Base32`, `Totp` (RFC 6238, **vecteurs officiels de l'annexe B rejoués**),
`PasswordHasher` (Argon2id 64 Mio/t=4/p=2, comparaison factice chronométrée),
`BackupCodes`, `AdminSession`, `Authenticator`, `AuditTrail`.

### `src/Service/Media/`

`UploadValidator`, `ImageProcessor`, `MediaStore`, plus les types `ValidatedImage`,
`ProcessedImage`, `StoredMedia`, `UploadRejection` et l'exception `UploadRejected`.

### `src/Service/Content/`

`HtmlSanitizer` (liste blanche sur **DOMDocument**, pas d'expression régulière),
`TranslationInput`, `PreviewToken` (HMAC signé, 24 h).

### `src/Repository/Admin/`

`CategoryAdminRepository`, `SeriesAdminRepository`, `ArtworkAdminRepository`,
`MediaAdminRepository`, `DashboardRepository`.

> **Pourquoi un namespace séparé.** Les dépôts de `Repository/` ne rendent que le
> publié ; ceux de `Repository/Admin/` voient tout. Un seul dépôt avec un drapeau
> finirait par laisser fuir un brouillon sur le site public, et personne ne s'en
> apercevrait avant que l'artiste ne le signale.

### Back-office

`/admin` — connexion, second facteur, tableau de bord, rubriques, séries, œuvres,
médiathèque, compte. Gabarit `layouts/admin.php`, `public/assets/css/admin.css`,
`public/assets/js/admin.js` (onglets de langue, confirmation d'abandon,
proposition de slug — **toutes des améliorations**, la page marche sans).

### Tests — 1320, tous verts

| Suite | Nombre | Durée |
| --- | --- | --- |
| `unit` | 619 | ~2 s |
| `integration` | 152 | ~5 s |
| `functional` | 152 | ~15 s |
| `security` | 397 | ~35 s |

La suite est passée de 10 s à ~60 s : Argon2id coûte 130 ms par hachage, et
`UploadTest` fait travailler GD pour de vrai. `UserFactory` mémorise déjà
l'empreinte du mot de passe par défaut pour tout le processus — sans quoi la
suite dépasserait les deux minutes.

Nouveaux fichiers de sécurité : `AuthTest`, `CsrfTest`, `UploadTest`.
`XssTest` rejoue désormais ses charges **à travers le vrai formulaire
d'administration** jusqu'à la page publique.

`tests/Support/ImageFixtures.php` fabrique les dix fichiers de 07-tests-tdd §4 —
JPEG/PNG/WebP valides, PHP déguisé, polyglotte GIF/PHP, **JPEG valide portant du
PHP en commentaire**, SVG, JPEG avec EXIF GPS, bombe de décompression, fichier
vide, fichier tronqué.

---

## Ce qu'il faut savoir avant de toucher au lot 2

1. **`Route::guest` inverse la règle.** Une route sous `/admin` est **fermée par
   défaut** ; seule `guest: true` l'ouvre. `AuthTest` parcourt la table et exige
   que la liste des routes ouvertes soit exactement celle de la connexion. Une
   route ajoutée sans y penser sera fermée, pas ouverte.
2. **`richText()` est la seule sortie non échappée du projet.** Elle rend du HTML
   **déjà assaini à l'écriture** par `HtmlSanitizer`. `EscapingTest` ne
   l'autorise que sous ce nom. Ne l'appelez jamais sur une valeur qui n'est pas
   passée par l'assainisseur.
3. **Le ré-encodage GD est la seule barrière** contre un JPEG parfaitement valide
   portant une charge : `finfo` et `getimagesize` le déclarent bon, à juste
   titre. Ne court-circuitez pas `MediaStore`.
4. **GD décode un JPEG tronqué sans la moindre alerte**, en comblant en gris —
   vérifié à 30 %, 55 % et 90 % du fichier. C'est la marque de fin de fichier,
   contrôlée par `UploadValidator`, qui le trahit.
5. **Aucun `@` nulle part.** Les alertes de GD sont **promues** en exception par
   un gestionnaire posé le temps de l'appel.
6. **Le verrouillage de compte doit être LU, pas seulement écrit.** Une faille de
   force brute sur le second facteur a été trouvée par la revue de sécurité du
   lot et corrigée : `verifyTwoFactor()` écrivait le compteur sans le relire. La
   leçon vaut pour le lot 3 — un test qui vérifie qu'une colonne est écrite ne
   vérifie pas que la règle est appliquée.
7. **Le montage masque les droits de l'image.** `docker-compose.yml` monte tout
   le dépôt sur `/var/www/html` : le `chown www-data` du Dockerfile est recouvert
   par l'appartenance de l'hôte. `docker/php/entrypoint.sh` rejoue l'opération à
   chaque démarrage — **ne le retirez pas**, et si vous ajoutez un répertoire
   inscriptible, ajoutez-le à sa liste. Voir le piège ci-dessous.

---

## Piège de déploiement rencontré au lot 2

Le back-office a été livré **inutilisable** en préproduction, et le symptôme
n'avait aucun rapport visible avec la cause. Il est consigné ici parce que la
même mécanique guette toute écriture que le lot 3 ajoutera — factures,
exports CSV, pièces jointes.

| | |
| --- | --- |
| **Symptôme** | `419 Formulaire expiré` à chaque tentative de connexion |
| **Ce qu'on cherche alors** | le jeton CSRF, la durée de session, `SameSite`, Heimdall |
| **Cause réelle** | `storage/sessions` non inscriptible par le conteneur |
| **Pourquoi** | `./:/var/www/html` masque le `chown www-data` de l'image ; le conteneur voit l'uid 1000 de l'hôte |
| **Pourquoi le lot 1 ne l'a pas vu** | le site public ne démarre aucune session et n'écrit nulle part |
| **Pourquoi c'était muet** | `session_start()` n'émet qu'une alerte et repart d'une session neuve ; `FileLogger` échouait aussi, donc le journal ne disait rien |

**Diagnostic en une commande** — deux `GET` successifs sur le formulaire avec le
même bocal à cookies. Si le jeton change, la session ne persiste pas :

```bash
curl -sS -c jar -o p1 "$URL/admin/connexion"
curl -sS -b jar -o p2 "$URL/admin/connexion"
grep -oE 'value="[a-f0-9]{64}"' p1 p2   # deux valeurs identiques = session OK
```

**Corrigé deux fois**, parce qu'il y avait deux défauts : `entrypoint.sh` rétablit
les droits au démarrage, et `PhpSession` refuse désormais de démarrer sur un
stockage inutilisable plutôt que de dégrader en silence.

---

## Écarts assumés avec les specs

| Écart | Motif |
| --- | --- |
| `vendor/` non suivi | **À rétablir au lot 3**, quand `stripe/stripe-php` arrive : liste blanche, sans les dépendances de développement, commité avec `composer.lock`. |
| Pas de `url()` ni `asset()` globales | Dépendent du préfixe résolu par la requête. Les gabarits reçoivent `$url`. |
| `Media::FORMATS` = `['webp','jpg']`, **sans AVIF** | **Décision du 2026-07-21, mesurée.** Un dérivé AVIF de 2400 px coûte **10,9 s** sur une image bruitée réaliste (WebP 1,2 s, JPEG 0,06 s) : les cinq largeurs demandent ~19 s, au-delà de ce qu'un mutualisé accorde à une requête. `libavif` est compilé dans l'image, AVIF est reporté au **lot 6** avec la génération différée que sa lenteur impose. |
| Budget de pixels à 40 Mpx, plus strict que les 12 000 px de la spec | 12 000 × 12 000 = 144 Mpx = 576 Mo en couleurs vraies, plus du double du `memory_limit`. Les deux bornes coexistent : chacune arrête ce que l'autre laisse passer. |
| Pas de QR code d'enrôlement 2FA | Demanderait une dépendance ou ~200 lignes de matrices de correction d'erreurs. La clé est affichée en groupes de quatre et le lien `otpauth:` fourni — toutes les applications acceptent la saisie manuelle. |
| Pas de middleware `RateLimit` | La limite de 06-securite §6.3 est propre à l'action ; elle est appliquée par le contrôleur de connexion. Un middleware générique aurait de toute façon eu besoin d'une table de limites par route. |
| Aucune limitation de débit sur le second facteur | Elle serait **morte** : elle mordrait à dix essais quand le verrouillage de compte ferme à cinq. Un garde-fou inatteignable ne donne qu'une fausse assurance. |
| Tableau de bord sans chiffre d'affaires ni commandes | Ces données n'existent pas avant le lot 3. Des compteurs à zéro laisseraient croire à une boutique déserte plutôt qu'à une boutique non encore construite. |
| SQL toléré dans `Core/Migrator` | Il construit le schéma dont les dépôts dépendent. Exception nommée dans `SqlLocationTest`. |
| Avertissements de longueur de ligne non bloquants | PSR-12 ne fixe pas de limite dure ; les forcer couperait des attributs HTML. |

---

## Questions ouvertes

- **Images réelles des œuvres** : toujours absentes. La préprod montre des
  placeholders engendrés à la trame du site. La médiathèque permet désormais à
  l'artiste de téléverser les vraies.
- **Textes anglais** : seules les rubriques ont une traduction ; le repli
  s'applique partout ailleurs. Le lot 5 s'en charge.
- **Reproductions** : `products` et `product_variants` n'existent pas encore.
  La médiathèque et le CRUD des œuvres sont prêts à les recevoir.
