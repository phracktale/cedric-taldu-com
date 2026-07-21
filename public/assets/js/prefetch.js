/**
 * Préchargement au survol.
 *
 * Implémentation maison, ~80 lignes, plutôt que Turbo : celui-ci intercepte les
 * formulaires et complique le tunnel de paiement et les redirections Stripe
 * pour un gain faible ici (ARCHITECTURE §5).
 *
 * Deux niveaux, décrits dans 02-front-public §5 :
 *   1. Speculation Rules quand le navigateur les gère ;
 *   2. sinon <link rel="prefetch"> après 65 ms d'intention.
 *
 * Rien n'est préchargé si le visiteur économise ses données, s'il est en 2G, ou
 * si la destination peut avoir un effet : panier, tunnel, administration.
 */

/** Délai d'intention : en deçà, le pointeur ne fait que passer. */
const DELAI_INTENTION = 65;

/** Au-delà, on consommerait plus de données qu'on n'en ferait gagner. */
const MAX_PRECHARGEMENTS = 6;

/** Aucune de ces destinations ne se précharge : elles ont un état. */
const EXCLUS = ['/panier', '/cart', '/commande', '/checkout', '/admin', '/deconnexion', '/logout'];

export function initPrefetch(base) {
  if (economiseLesDonnees()) {
    return;
  }

  const eligible = (lien) => estEligible(lien, base);

  if (HTMLScriptElement.supports?.('speculationrules')) {
    poserSpeculationRules(base);
    return;
  }

  const precharges = new Set();

  const precharger = (lien) => {
    if (precharges.size >= MAX_PRECHARGEMENTS || precharges.has(lien.href) || !eligible(lien)) {
      return;
    }

    precharges.add(lien.href);

    const balise = document.createElement('link');
    balise.rel = 'prefetch';
    balise.as = 'document';
    balise.href = lien.href;
    document.head.append(balise);
  };

  document.addEventListener('mouseover', (evenement) => {
    const lien = evenement.target.closest?.('a[href]');

    if (!lien) {
      return;
    }

    const minuteur = setTimeout(() => precharger(lien), DELAI_INTENTION);

    // Le pointeur a quitté le lien avant la fin du délai : il ne faisait que
    // passer, on annule.
    lien.addEventListener('mouseleave', () => clearTimeout(minuteur), { once: true });
  });

  document.addEventListener('touchstart', (evenement) => {
    const lien = evenement.target.closest?.('a[href]');
    if (lien) {
      precharger(lien);
    }
  }, { passive: true });
}

function poserSpeculationRules(base) {
  const regles = document.createElement('script');
  regles.type = 'speculationrules';
  regles.textContent = JSON.stringify({
    prefetch: [{
      source: 'document',
      where: {
        and: [
          { href_matches: `${base.replace(/\/$/, '')}/*` },
          ...EXCLUS.map((chemin) => ({ not: { href_matches: `*${chemin}*` } })),
        ],
      },
      eagerness: 'moderate',
    }],
  });

  document.head.append(regles);
}

function estEligible(lien, base) {
  // Jamais vers une autre origine : ni requête tierce, ni fuite de référent.
  if (lien.origin !== window.location.origin) {
    return false;
  }

  if (lien.hasAttribute('download') || (lien.target && lien.target !== '_self')) {
    return false;
  }

  // Un paramètre de requête peut porter un jeton ou un filtre à usage unique.
  if (lien.search !== '') {
    return false;
  }

  if (!lien.pathname.startsWith(base.replace(/\/$/, ''))) {
    return false;
  }

  return !EXCLUS.some((chemin) => lien.pathname.includes(chemin));
}

function economiseLesDonnees() {
  const connexion = navigator.connection;

  if (connexion?.saveData) {
    return true;
  }

  if (connexion?.effectiveType === '2g' || connexion?.effectiveType === 'slow-2g') {
    return true;
  }

  return window.matchMedia('(prefers-reduced-data: reduce)').matches;
}
