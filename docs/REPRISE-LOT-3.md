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

## Ce que tu construis

Le **lot 3 — Boutique et paiement**, tel que défini dans docs/specs/08-lots.md.
Rien de plus. Critère de fin : un achat d'original et un achat de reproduction
aboutissent en mode test Stripe, avec décrément de stock, e-mails et
impossibilité de double vente prouvée par test.

## Comment tu travailles

TDD strict, sans exception : test rouge, tu le montres, code minimal, vert,
refactorisation. Ordre imposé par 07-tests-tdd §6 — unitaire du domaine, puis
intégration de la persistance, puis fonctionnel du parcours, puis sécurité.

Branche `feature/lot-3-boutique-paiement`. Jamais de commit direct sur `main`.
Commits en français, `test(...)` AVANT le `feat(...)` correspondant.

**Déploiement.** À la fin du lot, tu fusionnes dans `main`, tu pousses, et tu
déploies sur Thor. Tu ne déploies pas en cours de lot.

Quand une spec est ambiguë ou qu'un point `@decision` te bloque, tu t'arrêtes et
tu poses la question, avec ta recommandation et son motif.
```

---

## Décisions à trancher AVANT de coder le lot 3

Ces `@decision` étaient signalés « bloquants à partir du lot 3 » dès le lot 2.
Ils le sont devenus.

| Point | Pourquoi il bloque |
| --- | --- |
| **TVA — tirages rehaussés** | `products.vat_category` a une valeur par défaut, mais la règle métier décide de `VatPolicy` et du contenu des factures. 03-boutique §5. |
| **Grille tarifaire de port** | `shipping_zones` et `shipping_rates` n'ont aucune donnée d'amorçage. `ShippingCalculator` ne peut pas être écrit sans les tranches réelles. |
| **Délai de rétractation** | Mention obligatoire dans les CGV et l'e-mail de confirmation. |
| **Numérotation des tirages** | `order_items.edition_number` : attribution à la commande ou à l'expédition ? |
| **Régime de TVA au démarrage** | `orders.vat_mode` par défaut `exempt_293b`. Une commande créée avant la bascule n'est jamais recalculée (01-modele §7.7) : le choix initial est définitif pour toutes les commandes de la période. |

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
composer test    # 1319 tests, ~55 s
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

### Tests — 1319, tous verts

| Suite | Nombre approx. |
| --- | --- |
| `unit` | 624 |
| `integration` | 175 |
| `functional` | 160 |
| `security` | 360 |

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
