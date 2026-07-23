/**
 * Ajout au panier en fetch, dégradable (03-boutique §2).
 *
 * AMÉLIORATION pure : sans JavaScript, le formulaire d'ajout est un POST
 * classique qui redirige vers le panier (POST-Redirect-GET, géré côté serveur).
 * Ce module intercepte l'envoi pour éviter le rechargement, met à jour la
 * pastille et affiche une confirmation discrète.
 *
 * Le jeton CSRF part dans un champ caché du formulaire ET dans l'en-tête, parce
 * que la même requête en fetch peut être validée par l'un ou par l'autre
 * (Core\Csrf). La CSP est stricte : ce fichier est chargé avec un nonce, sans
 * unsafe-inline.
 */

export function initCart() {
  document.querySelectorAll('form[data-cart-add]').forEach(initAddForm);
}

function initAddForm(form) {
  form.addEventListener('submit', async (event) => {
    // fetch indisponible : on laisse le POST classique se faire.
    if (typeof window.fetch !== 'function') {
      return;
    }

    event.preventDefault();

    const bouton = form.querySelector('[type="submit"]');
    if (bouton) {
      bouton.disabled = true;
    }

    try {
      const reponse = await fetch(form.action, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'fetch',
          'X-CSRF-Token': form.elements._token ? form.elements._token.value : '',
          Accept: 'application/json',
        },
        body: new FormData(form),
        credentials: 'same-origin',
      });

      if (!reponse.ok) {
        // Ligne refusée (déjà vendue, invalide) : on retombe sur le parcours
        // sans JS plutôt que d'inventer un message.
        form.submit();
        return;
      }

      const donnees = await reponse.json();
      majPastille(donnees.count);
      confirmer(form);
    } catch (erreur) {
      // Réseau coupé : le POST classique reste la voie de secours.
      form.submit();
    } finally {
      if (bouton) {
        bouton.disabled = false;
      }
    }
  });
}

function majPastille(count) {
  const pastille = document.querySelector('[data-cart-count]');
  if (!pastille || typeof count !== 'number') {
    return;
  }

  pastille.textContent = String(count);
  pastille.hidden = count === 0;
}

function confirmer(form) {
  const zone = form.querySelector('[data-cart-confirm]');
  if (zone) {
    zone.hidden = false;
  }
}
