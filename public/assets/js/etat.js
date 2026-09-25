/**
 * État du visiteur sur une page statique (retours du 2026-09-25, point 7).
 *
 * Une page générée est la même pour tous : elle ne porte ni jeton CSRF ni
 * pastille de panier. Ce module lit /api/etat, remplit les champs `_token` et
 * met la pastille à jour. Sans JavaScript, l'ajout au panier passe par la page
 * « Confirmer l'ajout », qui porte son propre jeton.
 */

export async function initEtat(base) {
  if (!('static' in document.body.dataset) || typeof window.fetch !== 'function') {
    return;
  }

  try {
    const reponse = await fetch(`${base.replace(/\/$/, '')}/api/etat`, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
    if (!reponse.ok) {
      return;
    }

    const etat = await reponse.json();

    if (typeof etat.token === 'string') {
      document.querySelectorAll('input[name="_token"]').forEach((champ) => {
        champ.value = etat.token;
      });
    }

    const pastille = document.querySelector('[data-cart-count]');
    if (pastille && typeof etat.cartCount === 'number') {
      pastille.textContent = String(etat.cartCount);
      pastille.hidden = etat.cartCount === 0;
    }
  } catch (erreur) {
    // Réseau coupé : les formulaires retombent sur la page de confirmation.
  }
}
