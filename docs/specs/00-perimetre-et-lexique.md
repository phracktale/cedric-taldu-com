# 00 — Périmètre et lexique

## 1. Le projet en une phrase

Un site bilingue FR/EN qui présente le travail de Cédric Taldu, artiste plasticien à Amiens,
et permet d'acquérir en ligne ses **œuvres originales** (pièces uniques) ainsi que des
**reproductions** (tirages d'art limités signés, et tirages standards), avec un back-office
autonome pour l'artiste.

## 2. Lexique métier

| Terme | Définition | Table |
|---|---|---|
| **Rubrique** | Famille technique d'œuvres : *Encres*, *Peintures*, et d'autres à venir. Extensible sans développement. | `categories` |
| **Série** | Regroupement transversal à l'intérieur d'une rubrique : *Piliers*, *Fondations*, *Figures*. Sert de filtre sur la page rubrique. | `series` |
| **Œuvre** | Pièce unique physique. Possède un statut de disponibilité et au plus un acheteur. | `artworks` |
| **Reproduction** | Offre de tirage rattachée à une œuvre. Deux types : *tirage d'art limité* (signé, numéroté, avec rehaut) et *tirage standard*. | `products` |
| **Variante** | Combinaison achetable d'une reproduction : taille × encadrement. Porte le prix, le stock et le poids. | `product_variants` |
| **Rehaut** | Intervention manuelle de l'artiste sur un tirage, qui en fait une pièce semi-originale. Propre aux tirages limités. | `products.has_rehaut` |
| **Livret** | Livret d'artiste 2026, document PDF téléchargeable. | `pages` + média |
| **Actu** | Article de blog : exposition, salon, travail en cours. | `posts` |

## 3. Périmètre fonctionnel

### Front public
- Accueil composé de modules ordonnés (hero, vitrine, triptyque, galeries, boutique,
  atelier, actus, contact) — voir `02-front-public.md`.
- Page rubrique : surtitre, titre, description, image de couverture, filtres par série,
  grille de miniatures.
- Fiche œuvre : visuel zoomable, caractéristiques, prix, statut, description, détails,
  achat de l'original, achat de reproductions, formulaire de question rattaché à l'œuvre,
  œuvres liées.
- Blog (liste + article), pages éditoriales (À propos, Livret, Mentions légales,
  Politique de confidentialité, CGV), contact.
- Panier, tunnel de commande, paiement Stripe, page de confirmation.
- Bilingue FR/EN avec URLs distinctes.

### Back-office
- Authentification administrateur, journal d'audit.
- CRUD rubriques, séries, œuvres, médias, reproductions et variantes.
- CRUD articles de blog et pages éditoriales.
- Gestion des commandes : consultation, marquage expédié, numéro de suivi, remboursement
  (déclenché dans Stripe, reflété ici), export CSV comptable.
- Boîte des messages de contact, avec rattachement à l'œuvre concernée.
- Réglages : coordonnées, frais de port par zone, mentions de TVA, textes récurrents.

## 4. Hors périmètre (à ne pas implémenter sans demande explicite)

- Comptes clients, historique de commandes côté client. Le suivi se fait par e-mail et par
  un lien de consultation signé, à durée de vie limitée.
- Codes promo, ventes flash, listes d'envie, avis clients, notation.
- Marketplace, multi-vendeurs, multi-devises. **Devise unique : EUR.**
- Impression à la demande automatisée : les tirages sont imprimés, stockés et expédiés par
  l'artiste. Le site gère un **stock réel**.
- Traduction automatique : les contenus EN sont saisis à la main dans le back-office.
- Comptabilité et suivi fiscal : le site produit des factures et un export CSV, rien de
  plus. Il ne suit pas de seuil, ne tient pas de livre de recettes et ne se substitue pas
  au comptable — la boutique n'est qu'une partie de l'activité de l'artiste.
- Analytics tiers et cookies publicitaires. Si une mesure d'audience est ajoutée un jour,
  elle sera sans cookie et auto-hébergée, sinon elle impose une bannière de consentement.

## 5. Décisions actées

| Sujet | Décision |
|---|---|
| Hébergement | **Preprod** : Docker `php:8.2-apache` sur Thor (homelab), derrière Heimdall, sur `customer.phracktale.com/cedric-taldu` — donc **sous un préfixe de chemin**. **Prod** : mutualisé (o2switch/OVH), Apache, PHP 8.2+, MySQL 8. Voir `09-environnements-deploiement.md` |
| Développement | Poste Windows **et** Linux du homelab, via Docker Compose |
| Reproductions | Stock physique tenu par l'artiste, décrémenté à la commande |
| Paiement | Stripe Checkout hébergé, derrière une interface `PaymentGateway` |
| Bilinguisme | `/fr/…` et `/en/…`, contenus traduits manuellement, slugs distincts par langue |
| Devise | EUR uniquement |
| Livraison | Zones FR / UE / Monde, grille par zone et tranche de poids, + remise en main propre à Amiens (gratuite) |

## 5 bis. Décisions du lot 3 — tranchées le 2026-07-21

Ces cinq points étaient marqués `@decision` et bloquaient `VatPolicy` et
`ShippingCalculator`. Ils sont clos. Le premier est **définitif** : `orders.vat_mode` est
figé à la création et n'est jamais recalculé (`01-modele-de-donnees.md` §7.7).

| Point | Décision | Motif |
|---|---|---|
| **Régime de TVA au démarrage** | `vat.mode = exempt_293b`, `vat.taxable_from` nulle | L'erreur n'est pas symétrique. Démarrer en franchise alors qu'on est redevable se corrige par un réglage daté. Démarrer en `taxed` sans l'être fait mentionner une TVA due au Trésor du seul fait de sa mention (CGI art. 283-3), sur des factures irréparables |
| **TVA des tirages rehaussés** | `standard_goods`, 20 % | Un giclée reste photomécanique au sens de l'art. 98 A ann. III : c'est la **planche** qui doit être exécutée à la main, pas l'exemplaire. Corrigeable par œuvre en back-office, sans développement |
| **Numérotation des tirages** | Attribution **au paiement**, dans le webhook, sous verrou de ligne | Seul instant où `editions_sold + q <= edition_size` est vérifiable atomiquement. À l'expédition, l'épuisement se découvrirait après encaissement et deux commandes pourraient viser le même numéro. Un remboursement brûle le numéro : l'artiste ajuste en back-office |
| **Rétractation** | 14 jours (L221-18), retour aux frais du client (L221-23), remboursement sous 14 jours après réception | Minimum légal, non négociable pour une vente à distance à un consommateur. Les frais de retour peuvent être laissés au client dès lors qu'il en est informé **avant** la commande — d'où la mention dans les CGV et l'e-mail de confirmation |
| **Grille de port** | Forfait unique par zone, une seule tranche à 10 kg : FR 9,00 €, UE 20,00 €, Monde 35,00 €. Franco FR à 300 €, UE à 800 €, aucun hors UE. Emballage forfaitaire 250 g. Au-delà de 10 kg : « devis d'expédition sur demande » | Simple à annoncer et à comprendre. Le modèle (`shipping_rates` par tranche) reste celui de `01-modele` §5 : passer plus tard à une grille fine est une insertion de lignes, pas un développement |

## 6. Points à trancher avec le client avant le lot correspondant

Ces points bloquent une partie du code ; ils sont marqués `@decision` dans les specs.

1. **Nom et statut juridique** à faire figurer dans les mentions légales et les CGV
   (SIRET, maison des artistes, hébergeur).
2. **Domaine de production final** : `cedrictaldu.com` sur mutualisé, ou maintien sur
   `customer.phracktale.com/cedric-taldu` ? Un sous-chemin de domaine tiers pénalise
   lourdement le référencement d'un site d'artiste et fragilise la confiance à l'achat.
   **Recommandation : preprod sur Thor, production sur un domaine propre.**
