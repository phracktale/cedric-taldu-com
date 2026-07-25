# Audit — Référencement, performance, accessibilité (lot 6)

Cet audit consigne l'état de conformité aux objectifs de `02-front-public.md §7`
et la méthodologie de mesure. Les scores Lighthouse chiffrés se relèvent en
navigateur contre la préprod (`https://customer.phracktale.com/cedric-taldu`) ou
la prod (`https://cedrictaldu.com`) — ils ne peuvent pas l'être depuis la CI PHP.
Le tableau de mesures ci-dessous est à compléter à chaque relevé.

## 1. Référencement — état

| Élément | Objectif | État |
|---|---|---|
| Titre / méta-description uniques | une par page | ✅ `metaTitle`/`metaDescription` par contrôleur, repli par défaut |
| `<link rel="canonical">` | absolu, depuis APP_URL | ✅ accueil, rubrique, œuvre, blog, pages |
| `hreflang` fr/en + x-default | émis seulement si la paire existe | ✅ toutes les pages indexables ; supprimé quand la traduction manque (§3) |
| JSON-LD | par type de page, `JSON_HEX_*` | ✅ Person/WebSite, Product+VisualArtwork, CollectionPage, BlogPosting/Event, BreadcrumbList |
| `sitemap.xml` | dynamique, 2 langues, `xhtml:link` | ✅ `SitemapController`, caché 1 h, exclut panier/tunnel/admin |
| `robots.txt` | interdit admin/panier/commande/webhooks/preview, pointe le sitemap | ✅ + `X-Robots-Tag: noindex` hors prod |
| Redirections 301 | au changement de slug, chaînes aplaties | ✅ `RedirectRepository` + `RedirectMiddleware` + `SlugHistory` |
| Pas de `Product` sur une œuvre non vendable | | ✅ offre absente si pas de prix |

## 2. Performance — état

| Élément | Objectif | État |
|---|---|---|
| Aucune ressource tierce | hors Stripe sur les pages de paiement | ✅ polices auto-hébergées (woff2), aucun CDN, aucune carte, aucune mesure d'audience |
| Images | `<picture>` WebP/JPEG, `srcset` 5 largeurs, `width`/`height`, `loading="lazy"` sauf visibles, `decoding="async"` | ✅ partial `picture`, dimensions posées (`aspect-ratio`) → CLS maîtrisé |
| AVIF | différé du lot 2 (coût de génération sur mutualisé) | ⏳ à réactiver avec génération différée (voir CLAUDE.md, décision 2026-07-21) |
| CSS | feuille unique auto-hébergée, `@view-transition` sous garde `prefers-reduced-motion` | ✅ ; CSS critique en ligne = piste d'optimisation restante |
| Préchargement au survol | Speculation Rules + repli, garde-fous saveData/2g | ✅ `prefetch.js` (lot 1) |

## 3. Accessibilité — checklist

| Point | État |
|---|---|
| Lien d'évitement vers le contenu | ✅ `.skip-link` → `#contenu` |
| `aria-current` sur l'entrée de menu active | ✅ menu Galerie + sélecteur de langue |
| Hiérarchie de titres (un seul `<h1>`) | ✅ vérifié par test sur l'accueil ; à re-vérifier par page au relevé |
| `<label>` liés à chaque champ | ✅ formulaires contact, tunnel, back-office |
| Erreurs annoncées (`role="alert"`) | ✅ erreurs de formulaire ; `role="status"` pour les confirmations |
| Focus visible | ✅ styles de focus dans `site.css` — à confirmer au clavier |
| Navigation clavier du sous-menu Galerie | ✅ `:focus-within` + `nav.js` (flèches, Échap) |
| `prefers-reduced-motion` | ✅ neutralise transitions, survols, `@view-transition` |
| Contraste AA | ⚠️ `--encre-40 #8C8983` sur `--papier` ne passe que pour du texte ≥ 18,66 px ; les surtitres porteurs d'information doivent être en `--encre-70` (02-front §7). À vérifier visuellement au relevé. |
| Texte alternatif des images | ✅ obligatoire à la publication (back-office), rendu dans `<picture>` |

## 4. Cibles Lighthouse (mobile, 4G simulée)

| Catégorie | Cible |
|---|---|
| Performance | ≥ 92 |
| Accessibilité | 100 |
| Bonnes pratiques | 100 |
| SEO | 100 |
| CLS | < 0,05 |
| LCP | < 2,0 s |

## 5. Relevés (à compléter en navigateur)

Méthode : Chrome DevTools → Lighthouse, profil « Mobile », throttling « Simulated
4G », mode navigation privée (pas d'extensions). Lancer sur : accueil, une
rubrique, une fiche œuvre, un article, le panier. Reporter la date, la page,
l'environnement et les cinq scores.

| Date | Environnement | Page | Perf | A11y | BP | SEO | CLS | LCP |
|---|---|---|---|---|---|---|---|---|
| _à venir_ | preprod | `/fr/` | | | | | | |

## 6. Suites restantes (hors périmètre du présent lot)

- Réactivation d'AVIF avec génération différée (lot 6 « images », dépend d'un
  mécanisme de génération asynchrone rejouable — pas de worker sur mutualisé).
- Extraction du CSS critique au-dessus de la ligne de flottaison.
- Relevés Lighthouse réels et, le cas échéant, corrections de contraste.
