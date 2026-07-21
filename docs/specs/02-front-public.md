# 02 — Front public

Le design system est **entièrement défini par les maquettes** de [maquette/](../../maquette/).
On en extrait `public/assets/css/site.css` sans réinterprétation : variables CSS, échelle
typographique, composants (`.wrap`, `.eyebrow`, `.stipple`, `.cadre`, `.dessin`, `.btn`,
`.legende`, `.oeuvres`, `.fiche`, `.liees`), points de rupture 900 px / 760 px / 560 px.

Palette de référence : `--papier #FAF9F6`, `--papier-chaud #F1EEE8`, `--mur #E7E3DB`,
`--encre #161513`, `--encre-70 #4A4843`, `--encre-40 #8C8983`, `--filet #D8D4CB`,
largeur maximale 1180 px. Typographies **Marcellus** (titres) et **Jost** 300/400/500
(textes) — **auto-hébergées en woff2**, jamais chargées depuis Google Fonts.

## 1. En-tête global

Présent sur toutes les pages, `position: sticky`, fond translucide + `backdrop-filter`.

- **Logo** : « Cédric Taldu » + surtitre « artiste plasticien — Amiens ». Lien vers l'accueil
  de la langue courante.
- **Menu principal** :

| Entrée | Comportement |
|---|---|
| À propos | Lien vers `/{locale}/a-propos` |
| Galerie | **Non cliquable.** Ouvre un sous-menu listant les rubriques publiées, alimenté depuis la base — aucune rubrique en dur |
| Actus | `/{locale}/actus` |
| Livret | `/{locale}/livret` |
| Contact | `/{locale}/contact` |
| Panier | Icône + pastille du nombre d'articles, style `.boutique` de la maquette. Masquée si vide, sauf sur les pages boutique |

- Sélecteur de langue FR / EN à droite du panier, pointant vers l'URL **équivalente** dans
  l'autre langue (voir `05-i18n-seo.md`).
- Le sous-menu Galerie est accessible : `<button aria-expanded>` + `<ul>`, ouverture au clic
  et au survol sur pointeur fin, navigation clavier (flèches, `Échap` ferme et rend le
  focus), fermeture au clic extérieur. **Sans JavaScript, le sous-menu reste ouvert et
  utilisable** (`:focus-within` + repli CSS).
- Le menu burger de la maquette est conservé sous 900 px, mais son `onclick` inline est
  déplacé dans `nav.js` — la CSP interdit les gestionnaires inline.

## 2. Page d'accueil

Modules dans l'ordre, tous éditables en back-office (`settings` + sélection d'entités) :

| # | Module | Contenu | Source |
|---|---|---|---|
| 1 | **Hero** | Surtitre, H1 SEO, baseline, deux boutons d'appel | Réglages |
| 2 | **Vitrine** | 3 œuvres mises en avant, grille `1.2fr 1fr 1.2fr`, celle du centre plus haute | Sélection d'œuvres |
| 3 | **Triptyque** | Surtitre, titre, intro, 3 cellules (Corps visible / divisible / vécu) | Réglages |
| 4 | **Galeries** | Une carte par rubrique publiée : image de couverture, titre, description, lien | `categories` |
| 5 | **Boutique** | Bande contrastée fond encre, texte de réassurance, bouton vers la boutique | Réglages |
| 6 | **Atelier** | Portrait + bio courte + bouton « Parcours et démarche » | Page `about` |
| 7 | **Actus** | 3 derniers articles publiés, format liste date / titre / lieu | `posts` |
| 8 | **Contact** | Texte + bouton vers le formulaire de contact | Réglages |

Le module 4 est **dynamique** : ajouter une rubrique en back-office fait apparaître une
carte supplémentaire, sans intervention. La grille passe de 2 colonnes à `auto-fit`
minmax(280px, 1fr) au-delà de deux rubriques.

## 3. Page rubrique — `/{locale}/galerie/{slug-rubrique}`

Reprend `maquette/boutique-encres.html`.

1. Fil d'Ariane.
2. **Surtitre**, **titre** (H1), **description** (2 paragraphes max recommandés), et
   **image de couverture** de la rubrique (celle uploadée en back-office) en bandeau ou en
   ouverture, selon la présence du média.
3. Filtres de **séries** : « Toutes » + une puce par série publiée de la rubrique. Filtrage
   par lien `?serie=slug`, rendu **côté serveur** ; une amélioration JS remplace le contenu
   sans rechargement, mais l'URL reste partageable et la page fonctionne sans JS.
4. **Grille de miniatures** : 3 colonnes ≥ 900 px, 2 colonnes ≥ 560 px, 1 colonne en deçà.
   Chaque vignette : cadre blanc, image au ratio réel de l'œuvre, légende `Titre — année`,
   technique et dimensions, et le marqueur « Disponible en boutique » si `status = available`
   et `price_cents` non nul.
5. Bande **méthode** (texte libre de la rubrique, champ optionnel).
6. **Passerelle boutique** : bande fond encre vers les œuvres disponibles.

Pagination à 24 œuvres, avec `rel="next"`/`rel="prev"` et un bouton « Voir plus » en
amélioration progressive.

## 4. Fiche œuvre — `/{locale}/oeuvre/{slug-oeuvre}`

Reprend `maquette/boutique-fiche-oeuvre.html`. Colonne visuelle collante à gauche
(`1.15fr`), colonne d'informations à droite (`1fr`), passage en une colonne sous 860 px.

**Colonne visuelle**
- Image principale en `<picture>` AVIF / WebP / JPEG, `srcset` sur 5 largeurs,
  `fetchpriority="high"`, dimensions explicites pour éviter tout décalage de mise en page.
- **Zoom** : clic ou touche `Entrée` ouvre une visionneuse plein écran avec zoom/panoramique
  (molette, pincement, double-clic, flèches clavier), fermeture par `Échap`, focus piégé,
  `aria-modal`. Implémentation maison dans `zoom.js`, sans bibliothèque, ~150 lignes,
  fondée sur `transform: scale()` et les *Pointer Events*. Chargement de la version 2400 px
  **uniquement** à l'ouverture.
- Vignettes des vues de détail sous l'image principale.
- Sans JS : le clic ouvre l'image pleine taille dans un nouvel onglet.

**Colonne d'informations**
1. Surtitre (« Œuvre originale · Pièce unique »).
2. H1 = titre de l'œuvre.
3. Caractéristiques sur une ligne : technique · dimensions · année · « Signée » si coché.
4. Prix (masqué si `price_cents` nul) et pastille de statut : *Disponible* / *Vendue* /
   *Réservée*.
5. Description, puis détails.
6. **Zone d'achat** :
   - Bouton plein **« Acquérir cette œuvre »** — affiché uniquement si
     `status = available` et prix défini. Sinon, mention « Œuvre vendue » et suggestion de
     voir les reproductions ou de poser une question.
   - Bouton vide **« Poser une question »** — ouvre en place un formulaire de contact
     **rattaché à l'œuvre** (`contact_messages.artwork_id` pré-rempli), pas un `mailto:`.
     Sans JS, le bouton pointe vers `/{locale}/contact?oeuvre={slug}`.
7. **Zone reproductions** — un bloc par `product` publié :
   - Titre du type de tirage et sa description.
   - Pour un tirage limité : « Tirage limité à N exemplaires, signé et numéroté »,
     mention du rehaut, et le nombre restant si ≤ 5 (« Plus que 3 exemplaires »).
   - Sélecteurs **Taille** et **Encadrement (oui / non)** : chaque combinaison correspond à
     une variante. Le prix et la disponibilité se mettent à jour à la sélection ; les
     combinaisons inexistantes ou en rupture sont désactivées, jamais masquées.
   - Quantité (1 à 5) puis « Ajouter au panier ».
   - **Sans JS** : le bloc est un `<form>` classique avec deux `<select>`, la validation de
     la combinaison se fait au serveur.
8. Bloc **garanties** (liste à puces pointillées de la maquette), texte issu des réglages.

**Bas de page** : « De la même recherche » — 3 œuvres liées, choisies dans cet ordre :
même série, puis même rubrique, puis les plus récentes ; jamais l'œuvre courante.

## 5. Préchargement au survol et navigation

Implémentation maison, sans Turbo (voir `ARCHITECTURE.md` §5). `prefetch.js` :

1. **Speculation Rules** quand le navigateur les supporte : injection d'un
   `<script type="speculationrules">` avec `"eagerness": "moderate"` sur les liens internes
   de type `document`, en excluant `/panier`, `/commande*`, `/admin*`, `/deconnexion`.
2. **Repli** pour les autres navigateurs :
   - Écoute de `mouseenter` (délai d'intention **65 ms**) et de `touchstart`.
   - Injection d'un `<link rel="prefetch" as="document" crossorigin="same-origin">`.
   - Uniquement : même origine, méthode GET, lien sans `download`/`target`, non déjà
     préchargé, dans la limite de **6 préchargements** par page.
   - Annulation si le pointeur quitte le lien avant la fin du délai.
3. **Garde-fous** : rien si `navigator.connection.saveData`, si `effectiveType` vaut `2g`
   ou `slow-2g`, ou si `prefers-reduced-data` est actif. Aucune requête vers une origine
   tierce. Aucun préchargement d'URL portant un paramètre de requête sensible.
4. **Transitions de page** : `@view-transition { navigation: auto }` en CSS, désactivé sous
   `prefers-reduced-motion`. Aucune dépendance JS, dégradation silencieuse.

## 6. Autres pages

| Page | URL FR | Contenu |
|---|---|---|
| À propos | `/fr/a-propos` | Page éditoriale `about` : portrait, parcours, démarche, expositions |
| Actus (liste) | `/fr/actus` | Articles paginés, image à la une, date, titre, extrait, lieu |
| Article | `/fr/actus/{slug}` | Image à la une, titre, date, corps, partage, articles voisins |
| Livret | `/fr/livret` | Présentation + téléchargement du PDF (lien servi par PHP, jamais un chemin direct) |
| Contact | `/fr/contact` | Formulaire général, coordonnées, mention RGPD |
| Mentions légales | `/fr/mentions-legales` | Page éditoriale `legal` |
| Confidentialité | `/fr/confidentialite` | Page éditoriale `privacy` |
| CGV | `/fr/conditions-generales-de-vente` | Page éditoriale `terms` |
| Panier / tunnel | voir `03-boutique-paiement.md` | |
| 404 / 500 | — | Reprennent la charte, proposent la galerie et la recherche |

## 7. Performance et accessibilité — objectifs vérifiés

- **Aucune requête vers une origine tierce** hors Stripe sur les pages de paiement.
- Images : AVIF en premier, `loading="lazy"` sauf les deux premières visibles,
  `decoding="async"`, `width`/`height` toujours présents.
- CSS unique et critique en ligne pour le rendu au-dessus de la ligne de flottaison ; le
  reste chargé de façon non bloquante.
- Objectifs Lighthouse mobile : Performance ≥ 92, Accessibilité **100**, Bonnes pratiques
  100, SEO 100. CLS < 0,05, LCP < 2,0 s en 4G simulée.
- Accessibilité : contrastes AA vérifiés (attention à `--encre-40 #8C8983` sur `--papier`,
  qui ne passe **que** pour du texte non essentiel ≥ 18,66 px — les surtitres doivent être
  renforcés à `--encre-70` s'ils portent de l'information), focus visible sur tous les
  éléments interactifs, hiérarchie de titres stricte, `aria-current` sur l'entrée de menu
  active, lien d'évitement vers le contenu principal, formulaires avec `<label>` liés et
  erreurs annoncées via `role="alert"`.
- `prefers-reduced-motion` neutralise le défilement doux, les transformations au survol et
  les transitions de vue.
