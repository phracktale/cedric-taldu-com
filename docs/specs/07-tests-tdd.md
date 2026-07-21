# 07 — Stratégie de test et protocole TDD

Le protocole quotidien est dans [../../tests/CLAUDE.md](../../tests/CLAUDE.md). Ce document
définit **ce qui doit être couvert** et **par quel type de test**.

## 1. Pyramide visée

```
          ┌──────────────┐
          │  Functional  │  ~60 tests   routes, HTML rendu, redirections, statuts
          ├──────────────┤
          │ Integration  │  ~90 tests   dépôts, transactions, contraintes, concurrence
          ├──────────────┤
          │   Security   │  ~35 tests   garde-fous de 06-securite.md
          ├──────────────┤
          │     Unit     │ ~250 tests   domaine pur : prix, panier, états, port, TVA, i18n
          └──────────────┘
```

Aucun test ne lance de serveur HTTP, n'appelle le réseau, ni ne dépend de l'horloge réelle.

## 2. Couverture par domaine fonctionnel

### 2.1 Domaine — unitaire, sans base

| Sujet | Cas obligatoires |
|---|---|
| `Money` | addition, multiplication par une quantité, égalité, refus de mélanger les devises, absence totale de flottant, arrondi bancaire si un taux de TVA est appliqué |
| `Cart` | ajout, fusion de lignes identiques, quantité max, retrait, panier vide, total recalculé, ligne devenue indisponible |
| `StockPolicy` | stock suffisant, insuffisant, exactement égal, variante inactive, édition épuisée |
| `PricingPolicy` | prix d'un original, d'une variante, sous-total, franco de port |
| `ShippingCalculator` | chaque zone, chaque tranche de poids, poids nul, poids hors barème, remise en main propre, franco atteint et non atteint |
| `VatPolicy` | franchise en base (TVA nulle + mention 293 B) ; régime taxé à taux unique 5,5 % puis 20 % ; **panier mixte** original + tirage avec port ventilé au prorata du HT ; commande antérieure à la date de bascule non recalculée ; changement de taux légal sans effet rétroactif ; arrondis vérifiés sur 1 000 combinaisons générées (somme des lignes = total, au centime) |
| `OrderStatus` | **toutes** les transitions valides et **au moins une** invalide par état |
| `ArtworkStatus` | disponible → réservée → vendue, expiration de réservation, refus de vendre deux fois |
| `Slug` | accents, apostrophes, majuscules, caractères chinois, chaîne vide, collision |
| `Translator` | clé présente, clé manquante, paramètres, repli FR pour un contenu EN absent |
| `OrderReference` | format, incrémentation, changement d'année |

### 2.2 Dépôts — intégration, base réelle

- Chaque méthode publique : cas nominal, résultat vide, valeur limite.
- Contraintes d'unicité (slug par langue, SKU, référence) : la violation lève l'exception
  attendue, pas une erreur SQL brute.
- Suppressions en cascade et `ON DELETE SET NULL` conformes au schéma.
- **Concurrence** : deux transactions simultanées sur la même œuvre — une seule aboutit.
  Deux décréments simultanés sur le même stock — jamais de stock négatif. Ces tests
  utilisent deux connexions PDO distinctes.
- Décrément de stock avec `stock_qty >= :q` et vérification du nombre de lignes affectées.
- Attribution des numéros d'édition : pas de doublon, pas de dépassement de `edition_size`.

### 2.3 Fonctionnel — `Kernel::handle()`

| Parcours | Assertions |
|---|---|
| Accueil FR et EN | 200, `<h1>` unique, modules présents, rubriques issues de la base |
| Rubrique | 200, nombre de vignettes, filtre par série, pagination, 404 si dépubliée |
| Fiche œuvre disponible | prix affiché, bouton « Acquérir » présent, JSON-LD `availability: InStock` |
| Fiche œuvre vendue | pas de bouton d'achat, mention « Vendue », `SoldOut` |
| Ajout au panier | avec et **sans** JavaScript (POST classique), pastille mise à jour |
| Panier avec œuvre vendue entre-temps | ligne retirée, message affiché, total recalculé |
| Tunnel complet | validation, création de commande `pending`, réservation, redirection Stripe |
| Retour Stripe avant webhook | page « en cours de confirmation », commande non `paid` |
| Webhook `checkout.session.completed` | commande `paid`, œuvre `sold`, stock décrémenté, e-mails en file |
| Rejeu du même webhook | aucun double effet |
| Contact et question sur une œuvre | message enregistré avec `artwork_id`, e-mail envoyé |
| Blog | liste paginée, article, article non publié → 404 |
| Sélecteur de langue | pointe vers l'URL équivalente, repli si traduction absente |
| Back-office | connexion, chaque CRUD (créer, modifier, publier, supprimer), déconnexion |
| 404 et 500 | charte respectée, aucune fuite d'information |

### 2.4 Sécurité

La liste des tests est dans `tests/CLAUDE.md`. Correspondance avec les règles :

| Règle de `06-securite.md` | Test |
|---|---|
| §1 Injection SQL | `SqlInjectionTest`, `SqlLocationTest` |
| §2 XSS et CSP | `XssTest`, `EscapingTest`, `HeadersTest`, `JsonLdEscapeTest` |
| §3 CSRF | `CsrfTest` |
| §4 Authentification | `AuthTest`, `SessionFixationTest`, `LockoutTest` |
| §5 Uploads | `UploadTest`, `PathTraversalTest` |
| §6 Anti-spam | `SpamTest`, `RateLimitTest`, `MailHeaderInjectionTest` |
| §7 Paiement | `PriceIntegrityTest`, `WebhookTest`, `OrderTransitionTest` |
| §8 Contrôle d'accès | `AuthTest`, `TokenTest` |
| §9 RGPD | `RetentionTest`, `AnonymizationTest`, `IpHashTest` |
| §10 Exploitation | `ErrorLeakTest`, `ExposureTest`, `HttpsTest`, `SpoofedHeaderTest` |
| §11 Dépendances | `composer audit` en intégration continue |
| Préfixe de chemin (`09-…` §3) | `BasePathTest` |

## 3. Doubles de test

| Réel | Double | Comportement |
|---|---|---|
| `StripeCheckoutGateway` | `FakeGateway` | Retourne des sessions déterministes, permet de forger des événements signés avec un secret de test |
| `SmtpMailer` | `ArrayMailer` | Accumule les messages, expose destinataire, sujet, corps et en-têtes pour assertion |
| `SystemClock` | `FrozenClock` | Instant fixe, avançable explicitement (`advance('+31 minutes')`) |
| `ImageProcessor` | réel | On teste le vrai traitement GD sur de petites images de fixture, y compris les fichiers malveillants |
| Générateur aléatoire | `SequenceRandom` | Jetons prévisibles en test, pour assertions exactes |

## 4. Fixtures

- Images de test dans `tests/Support/fixtures/` : JPEG valide, PNG valide, WebP valide,
  **PHP renommé en `.jpg`**, **polyglotte GIF/PHP**, SVG, JPEG avec EXIF GPS, image de
  50 000 × 50 000 px déclarés (bombe de décompression), fichier vide, fichier tronqué.
- Charges XSS et SQL dans `tests/Support/payloads.php`, rejouées sur **chaque** champ texte
  par les tests génériques — quand un champ est ajouté, il est couvert automatiquement.
- Événements Stripe enregistrés dans `tests/Support/stripe/` (JSON réels anonymisés).

## 5. Intégration continue

```
lint (php -l + PSR-12)
  └─ stan (PHPStan niveau 8, sans baseline)
       └─ unit
            └─ integration (MySQL de service, base construite depuis les migrations)
                 └─ functional
                      └─ security          ← bloquant
                           └─ audit (composer audit)
                                └─ couverture (seuils de tests/CLAUDE.md)
```

La construction échoue si un seul maillon échoue. Aucun test ne peut être marqué
« ignoré » sans un commentaire justifiant l'attente et un ticket associé.

## 6. Ordre d'écriture des tests dans une session de travail

Pour chaque fonctionnalité, dans cet ordre :

1. Test **unitaire** de la règle métier pure (le cœur : que doit-il se passer ?).
2. Test **d'intégration** de la persistance (est-ce correctement enregistré et relu ?).
3. Test **fonctionnel** du parcours (l'utilisateur obtient-il le bon résultat ?).
4. Test **de sécurité** de la surface ajoutée (que se passe-t-il si on l'attaque ?).

L'étape 4 n'est pas facultative. Une fonctionnalité livrée sans son test de sécurité est
une fonctionnalité non livrée.
