/**
 * Point d'entrée du JavaScript public.
 *
 * Modules ES natifs, aucune étape de build, aucune dépendance (CLAUDE.md).
 * Tout ce qui suit est une AMÉLIORATION : le site est entièrement utilisable
 * sans JavaScript, y compris la navigation, les filtres de série et l'accès aux
 * visuels en pleine taille.
 *
 * Le préfixe de chemin n'est jamais deviné : il est transmis une fois par
 * `<body data-base="…">` et lu ici (09-environnements §3.5).
 */

// ?v= : renouvelle une fois les modules mis en cache « immutable » avant le
// 2026-10-29 ; ils sont depuis revalidés à chaque chargement (public/.htaccess).
import { initNav } from './nav.js?v=20261029';
import { initPrefetch } from './prefetch.js?v=20261029';
import { initZoom } from './zoom.js?v=20261029';
import { initCart } from './cart.js?v=20261029';
import { initCheckout } from './checkout.js?v=20261029';
import { initEtat } from './etat.js?v=20261029';
import { initCarte } from './carte.js?v=20261029';
import { initSeries } from './series.js?v=20261029';

const base = document.body.dataset.base ?? '/';

initNav();
initPrefetch(base);
initZoom();
initCart();
initCheckout();
initEtat(base);
initCarte();
initSeries();
