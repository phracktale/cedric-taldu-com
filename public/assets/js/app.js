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

import { initNav } from './nav.js';
import { initPrefetch } from './prefetch.js';
import { initZoom } from './zoom.js';
import { initCart } from './cart.js';
import { initCheckout } from './checkout.js';

const base = document.body.dataset.base ?? '/';

initNav();
initPrefetch(base);
initZoom();
initCart();
initCheckout();
