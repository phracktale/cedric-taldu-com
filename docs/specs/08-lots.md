# 08 — Découpage en lots

Un lot = une branche = un livrable démontrable. On ne démarre pas le lot suivant tant que
le précédent n'est pas vert, revu et fusionné dans `main`.

Convention de branche : `feature/<lot>`, par exemple `feature/lot-3-fiche-oeuvre`.

---

## Lot 0 — Fondations
**Branche** `chore/lot-0-fondations`

- `composer.json`, autoload PSR-4, PHPUnit 11, PHPStan 8, PHP_CodeSniffer PSR-12.
- `docker/` + `docker-compose.yml` (php:8.2-apache, MySQL 8, MailHog), `.gitattributes`
  (LF), `.gitignore`, `.env.example`.
- `Core/` : `Kernel`, `Container`, `Router`, `Request`, `Response`, `Config`, `Env`,
  `Database`, `View`, `Session`, `Csrf`, `Clock`, `Logger`, `Validator`.
- Middlewares `SecurityHeaders`, `Locale`, `CsrfGuard`.
- **Gestion du préfixe de chemin** et `UrlGenerator` / `asset()` dès maintenant : c'est
  structurant, l'ajouter après coûte dix fois plus cher.
- `bin/migrate.php` + migration `0001_init.sql`.
- `tests/Support/` : `TestCase`, `FunctionalTestCase`, factories, doubles, fixtures.
- Suite `security` initiale : `HeadersTest`, `ExposureTest`, `EscapingTest`,
  `SqlLocationTest`, `BasePathTest`, `ErrorLeakTest`.

**Fait quand** : une page « Bonjour » répond en 200 sous `/cedric-taldu/fr/` **et** sous
`/fr/`, avec tous les en-têtes de sécurité, et la suite de sécurité est verte.

---

## Lot 1 — Catalogue en lecture
**Branche** `feature/lot-1-catalogue`

- Migrations : `media`, `categories`, `series`, `artworks` et leurs traductions.
- Dépôts et entités de domaine correspondants.
- Design system extrait des maquettes : `public/assets/css/site.css`, polices
  auto-hébergées, gabarits `layouts/public.php`, en-tête avec **menu Galerie dynamique**,
  pied de page.
- Pages : accueil (modules 1 à 8 en données statiques de configuration pour commencer),
  rubrique avec filtres de série et pagination, fiche œuvre en lecture seule.
- `nav.js`, `prefetch.js`, `zoom.js`.
- Jeu de données de démonstration reprenant les œuvres des maquettes.

**Fait quand** : le site public est navigable, identique aux maquettes, sans achat.

---

## Lot 2 — Back-office catalogue
**Branche** `feature/lot-2-back-office-catalogue`

- `users`, `audit_log`, authentification Argon2id, verrouillage, 2FA TOTP.
- Gabarit d'administration, tableau de bord minimal.
- CRUD rubriques, séries, œuvres.
- Médiathèque : upload sécurisé, ré-encodage GD, dérivés AVIF/WebP/JPEG, texte alternatif,
  point focal, déduplication.
- Aperçu avant publication, gestion des positions.

**Fait quand** : l'artiste peut créer une rubrique et une œuvre de bout en bout, sans
toucher au code, et `UploadTest` passe intégralement.

---

## Lot 3 — Boutique et paiement
**Branche** `feature/lot-3-boutique-paiement`

- `products`, `product_variants`, `carts`, `orders`, `shipping_*`, `stripe_events`.
- Domaine : `Cart`, `PricingPolicy`, `StockPolicy`, `ShippingCalculator`, `VatPolicy`,
  machine à états des commandes.
- Zone reproductions de la fiche œuvre, sélecteurs taille et encadrement.
- Panier, tunnel, `PaymentGateway` + `StripeCheckoutGateway` + `FakeGateway`.
- Webhook signé, idempotent, transactionnel.
- E-mails transactionnels bilingues.
- Back-office : reproductions, variantes, commandes, expédition, export CSV.
- Tests de concurrence et `PriceIntegrityTest`.

**Fait quand** : un achat d'original et un achat de reproduction aboutissent en mode test
Stripe, avec décrément de stock, e-mails et impossibilité de double vente prouvée par test.

---

## Lot 4 — Éditorial et contact
**Branche** `feature/lot-4-editorial`

- `posts`, `pages`, `contact_messages`, `redirects`.
- Blog public (liste, article), pages À propos / Livret / Mentions / Confidentialité / CGV.
- Éditeur riche avec assainissement à l'écriture.
- Formulaire de contact général **et** formulaire de question rattaché à une œuvre.
- `SpamGuard` complet : honeypot, horodatage signé, limitation de débit, heuristiques.
- Boîte des messages en back-office.
- Modules « Actus » et « Atelier » de l'accueil alimentés par la base.

**Fait quand** : l'artiste publie un article et reçoit un message de question sur une
œuvre, et `SpamTest` passe intégralement.

---

## Lot 5 — Bilinguisme complet
**Branche** `feature/lot-5-bilingue`

- Routes traduites, slugs par langue, sélecteur de langue, repli documenté.
- `resources/lang/fr.php` et `en.php`, test de parité des clés.
- Formats de date, nombre et monnaie par langue.
- E-mails dans la langue de la commande.
- Onglets FR/EN dans tous les formulaires du back-office.

**Fait quand** : le site entier est navigable en anglais, y compris un achat complet.

---

## Lot 6 — Référencement, performance, accessibilité
**Branche** `feature/lot-6-seo-perf-a11y`

- JSON-LD par type de page, canoniques, `hreflang`, `sitemap.xml`, `robots.txt`.
- Redirections 301 au changement de slug.
- Optimisation des images, CSS critique, `@view-transition`.
- Audit d'accessibilité complet : contrastes, focus, navigation clavier, lecteur d'écran.
- Mesures Lighthouse consignées dans `docs/audits/`.

**Fait quand** : les objectifs de `02-front-public.md` §7 sont atteints et documentés.

---

## Lot 7 — Mise en preprod sur Thor
**Branche** `chore/lot-7-preprod-thor`

- Réservation des ports dans `HOMELAB/.../port-routing-table.md`.
- Vhost Heimdall `location /cedric-taldu/`, certificat existant.
- Dossier de référence `HOMELAB/_CUSTOMERS/cedric-taldu/` (CLAUDE.md + vhost).
- Déploiement, migrations, jeu de données, comptes administrateurs.
- `noindex` de preprod, clés Stripe de test, MailHog, bandeau d'environnement.
- Recette avec le client sur `https://customer.phracktale.com/cedric-taldu`.

**Fait quand** : le client valide le site en preprod et le webhook Stripe de test
fonctionne à travers Heimdall.

---

## Lot 8 — Mise en production
**Branche** `chore/lot-8-production`

- Décisions en attente tranchées : domaine final, TVA, grille de port, CGV, mentions.
- Bascule des clés Stripe en production, webhook de production déclaré.
- Sauvegardes et restauration vérifiées.
- Checklist complète de `06-securite.md` §12.
- Contenus réels saisis par l'artiste, relecture, `sitemap` soumis.

---

## Chemin critique et dépendances

```
Lot 0 ──► Lot 1 ──► Lot 2 ──► Lot 3 ──► Lot 7 ──► Lot 8
                       └────► Lot 4 ──┘
                                Lot 5 ──► Lot 6 ──┘
```

Les lots 4 et 5 peuvent être menés en parallèle du lot 3 si le travail est réparti, mais le
lot 5 touche à toutes les vues : le mener **après** le lot 4 évite de traduire deux fois.
