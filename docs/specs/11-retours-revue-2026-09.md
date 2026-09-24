# 11 — Retours de la revue du 2026-09-24

Revue en direct du site avec l'artiste. Objectif affiché : un produit
**réutilisable par d'autres artistes**, donc paramétrable en back-office plutôt que
codé en dur. Ce document découpe les retours en incréments livrables, dans l'ordre
d'implémentation, et liste les décisions encore ouvertes.

## Décisions prises en séance

| Sujet | Décision |
| --- | --- |
| Statistiques | Matomo, sans reciblage publicitaire |
| Transporteur de démonstration | Colissimo, choix multi-transporteurs ensuite |
| Œuvre hors gabarit | Pas de livraison automatique : prise de rendez-vous (téléphone ou visio) |
| Remise en main propre | Gratuite dans un rayon d'environ 30 km autour d'Amiens, sinon expédition |
| Fiche œuvre | « De la même recherche » devient « De la même série » |

## Incrément 0 — Correctifs rapides ✅ `bugfix/retours-revue-quick-wins`

- Entrée de menu active (`aria-current`), style réglable par `nav.active_style` :
  souligné, gras, inverse vidéo, couleur.
- « Galerie » n'est plus en gras (graisse héritée par le bouton).
- Sous-menu Galerie au-dessus du contenu (`z-index`).
- Seul l'en-tête du site est collant : le H1 du livret et des articles ne l'est plus.
- Pages éditoriales élargies à `--contenu: 72rem` (≈ 1150 px).
- Tunnel : plus de vide entre « Vos coordonnées » et « Livraison » ; un seul `<main>`.
- Retrait : « Frais de déplacement offerts », rayon de 30 km annoncé.
- Image de couverture de page (À propos) affichée.
- Libellé « De la même série ».

## Incrément 1 — Apparence et accueil éditables ✅ `feature/apparence-accueil`

Livré : `Cta` (domaine) et `CtaLinker` ; écran de contenu par section
(`/admin/accueil/{section}`) ; écran `/admin/apparence`. Les couleurs saisies
(`#rrggbb` seulement) passent par un `<style nonce>` de la mise en page, la CSP
interdisant les attributs `style`. Reste pour l'incrément 2 : le CTA comme **bloc**.

- Écran back-office **Apparence** : style de l'entrée active, couleur d'accent.
- Formulaires d'édition du **contenu** de chaque section de l'accueil (aujourd'hui,
  seuls l'ordre et l'activation sont éditables ; le contenu ne vient que du seed).
- **Hero paramétrable** : image de fond (médiathèque), couleur de fond, couleur du
  texte, CTA (libellé + cible).
- **Widget CTA** réutilisable (libellé, cible, style, alignement gauche/centre/droite),
  qui remplace les boutons codés en dur (`#galeries` dans le hero et la boutique).
  Cible choisie dans une liste (page, galerie, boutique, livret) ou URL interne.
- CTA en fin d'actualité.

## Incrément 2 — Blocs de page ✅ `feature/blocs-contenu`

- Les blocs ne **remplacent** plus le corps de la page : le corps devient un bloc
  « texte riche » en tête de composition (migration du contenu existant).
- Nouveau bloc **CTA** (voir incrément 1), bloc image avec **sélecteur de
  médiathèque** au lieu d'un champ `src` libre.
- Blocs disponibles aussi sur les actualités et, à terme, sur l'accueil (une section
  « libre » composée de blocs, avec une fonction : informatif, CTA…).

## Incrément 3 — Galerie et navigation ✅ `feature/galerie-index`

- Page mère **Galerie** (`/fr/galerie`, `/en/gallery`) listant les sous-galeries avec
  une vignette ; « Galerie » devient un lien, le sous-menu est conservé.
- Page **Toutes les œuvres**, toutes galeries confondues, et le bouton qui y mène.
- Fil d'Ariane complet : Accueil › Galerie › Dessin à l'encre de Chine › Œuvre.
- **Générateur de menu** : ordre, libellé et visibilité des entrées ; ajout de
  rubriques fixes (Actus, Boutique, Galerie) et de pages.

## Incrément 4 — Images `feature/gabarits-images`

- Constat : aucune règle CSS ne recadre. Les défauts viennent du recadrage **manuel**
  qui écrase l'original, et de l'**agrandissement** des sources de moins de 320 px
  (`ImageProcessor::targetWidths`) : image floue.
- Ne jamais agrandir une source ; ne produire que les largeurs ≤ largeur d'origine.
- Recadrage non destructif : l'original est conservé, le cadrage est une donnée.
- Gabarit fixe par orientation (vertical 3/4, horizontal 4/3, carré 1/1) géré en CSS
  (`aspect-ratio` + `object-fit: contain`), avec un facteur de zoom réglable.
- Attributs `sizes` recalés sur les largeurs réelles d'affichage.

## Incrément 5 — Livraison `feature/livraison-colissimo`

- Case **Hors gabarit** sur l'œuvre : désactive l'expédition et bascule vers une
  demande de rendez-vous (téléphone/visio), sans paiement en ligne automatique.
- **Rayon de 30 km** : liste de codes postaux autour d'Amiens, éditable en
  back-office. Pas d'appel à un service de géocodage au moment de la commande.
- Poids, dimensions et profondeur par œuvre (le champ profondeur manque aujourd'hui).
- Interface `Carrier` (comme `PaymentGateway`) : Colissimo en premier, d'autres
  transporteurs branchables ensuite.

## Incrément 6 — Comptes clients et consentements `feature/comptes-clients`

- Comptes clients : **revient sur une décision du lot 0** (`0001_init.sql` :
  « aucun compte client »). Périmètre à fixer (voir plus bas).
- Case de consentement RGPD sur les formulaires qui collectent des données.
- Consentement newsletter, distinct et non précoché, avec preuve horodatée.
- Matomo : mesure d'audience, CSP à ouvrir sur l'origine Matomo.

## Décisions complémentaires (2026-09-24, après la revue)

1. **Comptes clients** : historique des commandes, **facture PDF** téléchargeable,
   détail de chaque commande (adresses de livraison et de facturation, transaction),
   **désinscription de la newsletter** depuis le compte.
2. **Colissimo** : intégrer le prestataire **sans contrat pour l'instant**. Le code
   est prêt derrière l'interface `Carrier` ; tant que les identifiants manquent, le
   transporteur fonctionne en mode grille (tarif poids/zone) et les appels à l'API
   (étiquettes, points de retrait) sont désactivés proprement.
3. **Matomo** : **auto-hébergé sur Thor**, en tant que service (conteneur dédié).
4. **Rayon de 30 km** : **distance calculée** depuis l'adresse, par une API de
   géocodage gratuite — la Base Adresse Nationale (`api-adresse.data.gouv.fr`),
   appelée côté serveur, avec repli sur l'expédition si le service ne répond pas.

## En attente

- **Données de l'artiste** : poids des œuvres et taille maximale d'un colis, pour
  paramétrer le hors-gabarit et les tranches de poids.
- **Contrat Colissimo** : identifiants à poser dans `.env` quand ils existeront.
