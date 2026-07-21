# 05 — Bilinguisme et référencement

## 1. Principes

- Deux langues : **fr** (par défaut) et **en**. Aucune autre langue n'est prévue, mais
  l'ajout d'une troisième ne doit demander qu'une ligne de configuration et des traductions.
- Les contenus sont **traduits à la main** en back-office. Aucune traduction automatique.
- La langue est **toujours** dans l'URL. Pas de détection implicite qui change le contenu
  d'une même URL : une URL = une langue = un contenu.

## 2. Structure des URL

Le préfixe d'application (`/cedric-taldu` en preprod, vide en prod) précède toujours la
langue. Il est ajouté par `UrlGenerator`, jamais écrit à la main.

| Page | FR | EN |
|---|---|---|
| Accueil | `/fr/` | `/en/` |
| À propos | `/fr/a-propos` | `/en/about` |
| Rubrique | `/fr/galerie/encres` | `/en/gallery/inks` |
| Rubrique filtrée | `/fr/galerie/encres?serie=piliers` | `/en/gallery/inks?series=pillars` |
| Œuvre | `/fr/oeuvre/articulation` | `/en/artwork/articulation` |
| Actus | `/fr/actus` | `/en/news` |
| Article | `/fr/actus/{slug}` | `/en/news/{slug}` |
| Livret | `/fr/livret` | `/en/booklet` |
| Contact | `/fr/contact` | `/en/contact` |
| Panier | `/fr/panier` | `/en/cart` |
| Commande | `/fr/commande` | `/en/checkout` |
| Confirmation | `/fr/commande/confirmation/{ref}` | `/en/checkout/confirmation/{ref}` |
| Back-office | `/admin/*` (non localisé) | — |
| Webhook | `/webhooks/stripe` (non localisé) | — |

- Les **segments de route** sont eux-mêmes traduits (`galerie`/`gallery`,
  `oeuvre`/`artwork`) : ils vivent dans `config/routes.php`, une entrée par langue.
- Les **slugs de contenu** sont propres à chaque langue (`artwork_translations.slug`).
- `/` sans langue → redirection **302** vers `/fr/` ou `/en/` selon `Accept-Language`, avec
  `Vary: Accept-Language`. La redirection est en 302 et non en 301 : la négociation peut
  changer d'un visiteur à l'autre.
- Le choix explicite de langue par le sélecteur est mémorisé dans un cookie
  (`ct_locale`, 1 an, `SameSite=Lax`) et prime sur `Accept-Language` **uniquement** pour la
  redirection de la racine.

## 3. Repli quand la traduction manque

`@decision` retenue : le contenu FR est obligatoire, le contenu EN est facultatif.

| Situation | Comportement |
|---|---|
| Contenu EN complet | Page EN servie normalement, `hreflang` réciproque entre FR et EN |
| Contenu EN absent | La page `/en/...` est servie avec le **texte FR**, précédée d'une mention discrète « This text is only available in French », et **`hreflang` de la paire non émis** pour cette page |
| Contenu FR absent | Impossible : la validation du back-office l'interdit |
| Slug EN absent | Le slug FR est utilisé pour l'URL EN |

Ce repli est implémenté dans `Service\I18n\Translator::content()` et testé pour chaque type
d'entité. Il ne doit **jamais** produire un 404 ni une page vide.

## 4. Traduction de l'interface

- Fichiers `resources/lang/fr.php` et `en.php` : tableaux plats de clés
  (`shop.add_to_cart`, `artwork.sold`, `checkout.terms_required`).
- `t('clé', ['param' => …])` échappe par défaut ; une variante explicite `tRaw()` existe
  pour les rares chaînes contenant du balisage, et son usage est limité par un test.
- Une clé manquante lève une exception en environnement de développement et retourne la clé
  en production, avec journalisation. Un test vérifie que **fr.php et en.php ont exactement
  les mêmes clés**.
- Les formats de date, de nombre et de monnaie suivent la langue :
  `45,00 €` en FR, `€45.00` en EN. Aucun formatage en dur.
- Les e-mails transactionnels sont envoyés dans la langue de la commande.

## 5. Référencement

**Balises par page** — titre unique, méta-description unique, une seule `<h1>`.
Génération par défaut si les champs SEO sont vides, à partir du contenu.

**Canonique et hreflang** :
```html
<link rel="canonical" href="https://cedrictaldu.com/fr/oeuvre/articulation">
<link rel="alternate" hreflang="fr" href="https://cedrictaldu.com/fr/oeuvre/articulation">
<link rel="alternate" hreflang="en" href="https://cedrictaldu.com/en/artwork/articulation">
<link rel="alternate" hreflang="x-default" href="https://cedrictaldu.com/fr/oeuvre/articulation">
```
Les URL absolues sont construites depuis `APP_URL`, jamais depuis l'en-tête `Host`
(protection contre l'empoisonnement de cache par en-tête).

**Données structurées JSON-LD**, reprises des maquettes et alimentées par la base :
- Accueil : `Person` (nom, métier, lieu, formation, contacts) + `WebSite`.
- Fiche œuvre : `Product` avec `offers` (prix réel, `priceCurrency: EUR`, `availability`
  `InStock` / `SoldOut` selon le statut, `itemCondition`, `url`), `brand` = `Person`,
  `material` = technique, plus `VisualArtwork` (`artform`, `artMedium`, `width`, `height`)
  qui décrit l'œuvre bien mieux que `Product` seul.
- Rubrique : `CollectionPage` + `ItemList` des œuvres.
- Article : `BlogPosting` ou `Event` quand `event_date` est renseignée.
- Fil d'Ariane : `BreadcrumbList` sur toutes les pages profondes.
- **Le JSON-LD est produit par `Service\Seo\StructuredData` avec `json_encode` et les
  drapeaux `JSON_HEX_*`** — jamais par concaténation de chaînes dans un template.
  Un titre d'œuvre contenant `</script>` ne doit pas casser la page. Test dédié.

**`sitemap.xml`** généré dynamiquement, mis en cache 1 h : accueil, rubriques, œuvres
publiées, articles, pages éditoriales, dans les deux langues, avec les liens `xhtml:link`
alternatifs et les `lastmod` réels. Exclut le panier, le tunnel, l'admin, les aperçus.

**`robots.txt`** : interdit `/admin`, `/panier`, `/commande`, `/webhooks`, `?preview=` ;
pointe vers le sitemap. En preprod, interdit **tout** (voir `09-…` §7).

**Redirections** : le changement d'un slug publié crée une redirection 301 depuis l'ancien
slug (table `redirects` : `from_path`, `to_path`, `locale`, `hits`, `created_at`).
Les chaînes de redirection sont résolues à l'écriture pour éviter les rebonds successifs.

**Images** : texte alternatif obligatoire à la publication d'une œuvre, noms de fichiers
dérivés du titre et de la technique (`articulation-encre-de-chine-cedric-taldu-1024.avif`),
`<picture>` avec `srcset` et `sizes` cohérents.

## 6. Ce qui est explicitement évité

- Pas de contenu dupliqué entre `/fr/` et `/en/` sans `hreflang` correct.
- Pas de redirection automatique fondée sur la géolocalisation : elle empêche
  l'indexation correcte et frustre les visiteurs.
- Pas de paramètres d'URL indexables pour le filtrage par série : le canonique de
  `?serie=…` pointe vers la page rubrique nue.
- Pas de balisage `Product` sur une œuvre non destinée à la vente.
