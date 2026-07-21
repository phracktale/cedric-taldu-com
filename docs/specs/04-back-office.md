# 04 — Back-office

Objectif : **l'artiste est autonome**. Aucune intervention de développement ne doit être
nécessaire pour ajouter une rubrique, une œuvre, une reproduction, un article ou pour
traiter une commande.

URL : `/admin` (non localisée, interface en français). Aucune inscription publique.

## 1. Accès et sécurité de session

- Connexion e-mail + mot de passe. Hachage **Argon2id** (`PASSWORD_ARGON2ID`,
  `memory_cost` 64 Mio, `time_cost` 4, `threads` 2), revérifié et réencodé à la connexion
  si les paramètres ont changé.
- Limitation : 5 échecs → verrouillage du compte 15 minutes (`locked_until`), plus une
  limitation par IP indépendante. Message d'erreur **identique** que le compte existe ou
  non, et durée de traitement constante (comparaison factice si l'utilisateur est inconnu).
- Session régénérée à la connexion, expiration après 30 min d'inactivité et 12 h en absolu.
- 2FA TOTP **optionnelle** mais implémentée dès le lot back-office (`totp_secret`), avec
  codes de secours à usage unique.
- Toute action modifiant une donnée est tracée dans `audit_log` (acteur, action, entité,
  différentiel des champs, IP hachée).
- Rôles : `admin` (tout) et `editor` (contenu éditorial et catalogue, **pas** les
  commandes, les réglages ni les utilisateurs).
- Déconnexion : destruction de session côté serveur, pas seulement suppression du cookie.

## 2. Tableau de bord

Commandes du mois, chiffre d'affaires, commandes en attente d'expédition, messages non lus,
alertes : stock faible (< 3), édition limitée bientôt épuisée, œuvres réservées expirées,
anomalies de commande, échecs d'envoi d'e-mail.

Le chiffre d'affaires affiché est **celui du site uniquement**, et l'interface le dit
explicitement. Aucun suivi de seuil de franchise n'est implémenté : la boutique n'est pas
la seule source de revenus de l'artiste, un compteur partiel donnerait une fausse
assurance. Voir `03-boutique-paiement.md` §5.7.

## 3. CRUD Rubriques

`/admin/rubriques`

| Champ | Type | Règles |
|---|---|---|
| Surtitre | texte 160, par langue | facultatif |
| Titre | texte 200, par langue | **requis en FR** |
| Slug | texte, par langue | généré depuis le titre, modifiable, unique par langue ; à la modification d'un slug publié, proposition de redirection 301 automatique |
| Description | éditeur riche, par langue | assainie à l'écriture |
| Texte « méthode » | éditeur riche, par langue | facultatif, bande basse de la page rubrique |
| Photo de couverture | upload | une image, recadrage et point focal |
| Position | entier | glisser-déposer dans la liste |
| Publié | booléen | une rubrique dépubliée disparaît du menu Galerie et renvoie 404 |

Suppression **bloquée** si la rubrique contient des œuvres : proposer de les déplacer.

## 4. CRUD Séries

`/admin/rubriques/{id}/series` — titre, slug, description par langue, position, publication.
Une série appartient à une seule rubrique. Suppression → les œuvres passent à « sans série ».

## 5. CRUD Œuvres

`/admin/oeuvres` — liste filtrable (rubrique, série, statut, publication, recherche),
tri par position en glisser-déposer, actions groupées (publier, dépublier, changer de
rubrique).

**Formulaire** (onglets FR / EN pour les champs traduisibles) :

| Bloc | Champs |
|---|---|
| Identification | Référence atelier (unique), rubrique, série, année |
| Contenu | Surtitre, Titre, Description, Détail — par langue |
| Caractéristiques | Technique (avec autocomplétion sur les valeurs existantes), largeur et hauteur en mm, case **Signée**, poids en grammes |
| Commerce | Prix **TTC** en euros (saisi en euros, stocké en centimes), catégorie de TVA (défaut « œuvre originale — 5,5 % »), **Statut** : brouillon / disponible / réservée / vendue / non destinée à la vente |
| Médias | Image principale + vues de détail, réordonnables, texte alternatif par langue |
| Publication | Publié, date de publication, position |
| SEO | Meta title, meta description par langue, aperçu du rendu Google |

Règles :
- Le passage manuel en « vendue » est autorisé (vente en atelier, en salon) et journalisé.
- Le passage manuel de « vendue » à « disponible » est autorisé **sauf** si l'œuvre est
  rattachée à une commande payée : confirmation explicite et trace obligatoire.
- Publier une œuvre sans image principale est impossible.
- Prix vide + statut « disponible » → avertissement bloquant : l'œuvre serait affichée
  disponible sans pouvoir être achetée.
- Aperçu avant publication via un lien signé à durée limitée (`?preview=<jeton>`).

## 6. CRUD Reproductions

`/admin/oeuvres/{id}/reproductions`

- Une œuvre peut avoir 0, 1 ou 2 reproductions (tirage limité, tirage standard).
- Champs du produit : type, titre et description par langue, taille de l'édition
  (obligatoire pour un tirage limité), cases **signé** et **avec rehaut**, **catégorie de
  TVA** (défaut « reproduction — 20 % »), publication.
- L'aide en ligne du champ TVA rappelle la règle : un tirage giclée signé, numéroté et
  rehaussé reste une reproduction photomécanique au sens fiscal ; la catégorie
  « estampe originale — 5,5 % » n'est légitime que pour une planche exécutée à la main par
  l'artiste. Le choix est journalisé dans `audit_log`.
- **Variantes** en tableau éditable : taille (libellé + dimensions), encadré oui/non, SKU
  (généré, modifiable, unique), prix, stock, poids, actif.
- Un **générateur de variantes** croise une liste de tailles et l'option d'encadrement pour
  créer les combinaisons manquantes sans écraser les existantes.
- `editions_sold` est en lecture seule ; sa modification passe par un formulaire de
  correction dédié, tracé, avec motif obligatoire.
- Baisser le stock en dessous des quantités déjà vendues est refusé.

## 7. Médiathèque

`/admin/medias`

- Upload multiple par glisser-déposer, avec barre de progression et reprise sur erreur.
- Contrôles à l'upload (détaillés dans `06-securite.md` §5) : type MIME réel, dimensions,
  taille maximale 25 Mo, **ré-encodage systématique** par GD.
- Génération des dérivés AVIF / WebP / JPEG en 320, 640, 1024, 1600 et 2400 px.
  Génération synchrone si le temps d'exécution le permet, sinon différée et rejouable
  (aucun worker disponible sur mutualisé).
- Déduplication par empreinte SHA-256.
- Texte alternatif par langue, point focal, légende.
- Suppression refusée si le média est utilisé ; la liste des usages est affichée.

## 8. Commandes

`/admin/commandes`

- Liste : référence, date, client, montant, statut, mode de remise, indicateur d'anomalie.
- Fiche : lignes figées, adresses, montants, historique des statuts, événements Stripe
  reçus, lien vers le paiement dans le tableau de bord Stripe.
- Actions : **marquer expédiée** (transporteur + numéro de suivi → e-mail au client),
  renvoyer l'e-mail de confirmation, ajouter une note interne, générer la facture PDF ou
  HTML imprimable avec la mention de TVA applicable.
- **Le statut « payée » n'est jamais attribuable à la main.** Seul le webhook Stripe le
  produit. Le back-office n'expose pas cette transition.
- Les remboursements se font dans Stripe ; le back-office affiche l'état et permet de
  décider, séparément, de la réintégration du stock.
- Export CSV comptable par période, encodage UTF-8 avec BOM, séparateur `;`, et
  **protection contre l'injection de formule** : toute valeur commençant par `=`, `+`,
  `-` ou `@` est préfixée d'une apostrophe. Testé.

## 9. Blog et pages

`/admin/actus` — image à la une, titre, slug, extrait, corps en éditeur riche, date et lieu
d'événement, date de publication (publication différée), SEO, par langue.

`/admin/pages` — pages à code fixe (`about`, `booklet`, `legal`, `privacy`, `terms`) :
titre, corps, SEO, et pièce jointe PDF pour le livret. On ne peut pas supprimer une page à
code fixe, seulement la dépublier — sauf `legal`, `privacy` et `terms` qui restent toujours
accessibles pour des raisons réglementaires.

**Éditeur riche** : liste blanche stricte de balises (`p`, `br`, `strong`, `em`, `ul`,
`ol`, `li`, `h2`, `h3`, `blockquote`, `a`, `figure`, `figcaption`, `img`) et d'attributs
(`href`, `title`, `src`, `alt`, `width`, `height`). Les `href` sont limités aux schémas
`https`, `mailto` et aux liens internes. Le HTML est **assaini au moment de
l'enregistrement**, et c'est la version assainie qui est stockée.

## 10. Messages de contact

`/admin/messages` — liste filtrable par statut, avec l'œuvre concernée quand il y en a une.
Lecture, marquage lu / répondu / indésirable, réponse par le client de messagerie de
l'artiste (lien `mailto:` pré-rempli, l'envoi ne passe pas par le site). Suppression
définitive avec confirmation. Purge automatique des messages indésirables à 90 jours.

## 11. Réglages

`/admin/reglages`

- Identité : coordonnées, réseaux sociaux, mentions de pied de page.
- Contenus de l'accueil : hero, triptyque, bande boutique, bande contact, garanties.
- Boutique : zones et grilles de frais de port, forfait d'emballage, seuil de franco,
  délai de réservation pendant le paiement.
- **TVA** : régime (`franchise en base` / `taxé`), **date de bascule**, table des taux par
  catégorie avec leurs périodes de validité. Pas de seuil, pas d'alerte : la bascule est
  décidée par le comptable, hors du site.
  Le changement de régime est une opération sensible : confirmation en deux temps, motif
  obligatoire, journalisation, et rappel explicite qu'aucune commande antérieure à la date
  de bascule ne sera recalculée. Un aperçu montre l'effet sur trois paniers types avant
  validation.
- E-mails : expéditeur, adresse de notification, textes d'introduction et de signature.
- Maintenance : bascule en mode maintenance avec liste blanche d'IP.

Toute modification de réglage est journalisée avec l'ancienne et la nouvelle valeur.

## 12. Ergonomie

- Interface sobre reprenant la charte du site, en une seule feuille de style.
- Enregistrement en brouillon automatique toutes les 30 s sur les formulaires longs
  (stockage local navigateur, jamais côté serveur), avec restauration proposée.
- Confirmation avant abandon d'un formulaire modifié.
- Aperçu direct de la fiche publique depuis le formulaire d'édition.
- Fonctionne sans JavaScript pour toutes les opérations critiques : le glisser-déposer, le
  brouillon automatique et le téléversement multiple sont des améliorations.
