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

## 6. Points à trancher avec le client avant le lot correspondant

Ces points bloquent une partie du code ; ils sont marqués `@decision` dans les specs.

1. **TVA — tranché sur le principe, à confirmer sur deux détails.**
   Démarrage en **franchise en base** (art. 293 B du CGI, mention obligatoire sur les
   factures). La bascule vers un régime taxé est prévue par conception : c'est un réglage
   daté, sans migration ni développement (voir `03-boutique-paiement.md` §5).
   Taux retenus une fois taxé : **5,5 %** sur les œuvres originales (art. 278-0 bis I du
   CGI, taux généralisé au 1ᵉʳ janvier 2025), **20 %** sur les reproductions.
   Reste à confirmer par le comptable : le **traitement des tirages rehaussés** — retenus
   à 20 % par défaut, un tirage giclée restant une reproduction photomécanique au sens de
   l'art. 98 A ann. III du CGI même signé, numéroté et rehaussé à la main.
   Le site **ne suit aucun seuil de franchise** : il n'est pas la seule source de revenus
   de l'artiste, un compteur partiel serait trompeur. La bascule de régime est manuelle.
2. **Grille tarifaire de port** : montants réels par zone et par tranche de poids.
3. **Délai de rétractation** : 14 jours légaux pour les reproductions. Les œuvres d'art
   originales personnalisées peuvent en être exclues — à confirmer avec le rédacteur des CGV.
4. **Numérotation des tirages limités** : attribution automatique et croissante du numéro à
   la commande (retenu par défaut), ou choix du numéro par l'acheteur ?
5. **Nom et statut juridique** à faire figurer dans les mentions légales et les CGV
   (SIRET, maison des artistes, hébergeur).
6. **Domaine de production final** : `cedrictaldu.com` sur mutualisé, ou maintien sur
   `customer.phracktale.com/cedric-taldu` ? Un sous-chemin de domaine tiers pénalise
   lourdement le référencement d'un site d'artiste et fragilise la confiance à l'achat.
   **Recommandation : preprod sur Thor, production sur un domaine propre.**
