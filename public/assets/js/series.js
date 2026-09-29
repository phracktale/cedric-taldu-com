/**
 * Filtre par série sans rechargement (retour client du 2026-09-29).
 *
 * Amélioration : les filtres restent de vrais liens (?serie=…), rendus côté
 * serveur — sans JavaScript, pour les moteurs et pour partager l'URL. Ici, un
 * clic va chercher la même URL, n'en garde que la grille des œuvres et la met
 * à la place de l'actuelle ; l'adresse suit (history), le bouton « retour »
 * aussi. Là où le navigateur le permet, la réorganisation est animée (View
 * Transitions). En cas d'échec, on retombe sur la navigation normale.
 */

function remplacerGrille(nouvelle) {
  const actuelle = document.querySelector('[data-grille-oeuvres]');
  if (!actuelle || !nouvelle) return false;

  const echanger = () => actuelle.replaceWith(nouvelle);
  const reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (typeof document.startViewTransition === 'function' && !reduit) {
    document.startViewTransition(echanger);
  } else {
    echanger();
  }

  return true;
}

function marquerSerie(nav, url) {
  nav.querySelectorAll('a.serie').forEach((lien) => {
    if (lien.href === url) {
      lien.setAttribute('aria-current', 'page');
    } else {
      lien.removeAttribute('aria-current');
    }
  });
}

async function charger(nav, url, empiler) {
  try {
    const reponse = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
    if (!reponse.ok) throw new Error(String(reponse.status));

    const page = new DOMParser().parseFromString(await reponse.text(), 'text/html');
    if (!remplacerGrille(page.querySelector('[data-grille-oeuvres]'))) throw new Error('grille absente');

    marquerSerie(nav, url);
    if (empiler) history.pushState({ serie: url }, '', url);
  } catch (erreur) {
    // Réseau coupé, réponse inattendue : navigation classique.
    window.location.href = url;
  }
}

export function initSeries() {
  const nav = document.querySelector('[data-filtre-series]');
  if (!nav || typeof window.fetch !== 'function' || typeof DOMParser === 'undefined') {
    return;
  }

  nav.addEventListener('click', (evenement) => {
    const lien = evenement.target.closest('a.serie');
    // Clic du milieu, Ctrl/Cmd+clic : nouvel onglet, comme un lien ordinaire.
    if (!lien || evenement.button !== 0 || evenement.metaKey || evenement.ctrlKey || evenement.shiftKey || evenement.altKey) {
      return;
    }
    evenement.preventDefault();
    if (lien.getAttribute('aria-current') === 'page') return;
    charger(nav, lien.href, true);
  });

  window.addEventListener('popstate', () => charger(nav, window.location.href, false));
}
