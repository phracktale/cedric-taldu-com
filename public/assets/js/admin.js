/**
 * Améliorations du back-office.
 *
 * 04-back-office §12 : « Fonctionne sans JavaScript pour toutes les opérations
 * critiques : le glisser-déposer, le brouillon automatique et le téléversement
 * multiple sont des améliorations. »
 *
 * Ce module ne fait donc qu'ajouter du confort par-dessus une interface qui
 * marche déjà : onglets de langue, confirmation avant abandon, avertissement
 * avant suppression. Rien de ce qu'il fait n'est nécessaire pour saisir une
 * œuvre.
 *
 * Module ES sans étape de construction, chargé avec le nonce de la CSP.
 */

/* ------------------------------------------------------------- onglets FR/EN */

/**
 * Les panneaux de langue sont tous visibles dans le HTML servi : sans ce
 * module, l'artiste saisit le français puis l'anglais en faisant défiler. Le
 * repliement n'a lieu qu'une fois le module chargé.
 */
function activerOnglets(groupe) {
  const panneaux = Array.from(groupe.querySelectorAll('.panneau-langue'));
  if (panneaux.length < 2) return;

  const barre = document.createElement('div');
  barre.className = 'onglets';
  barre.setAttribute('role', 'tablist');

  const montrer = (actif) => {
    panneaux.forEach((panneau) => {
      panneau.hidden = panneau !== actif;
    });
    Array.from(barre.children).forEach((bouton) => {
      bouton.setAttribute('aria-selected', String(bouton.dataset.cible === actif.dataset.langue));
    });
  };

  panneaux.forEach((panneau) => {
    const bouton = document.createElement('button');
    bouton.type = 'button';
    bouton.setAttribute('role', 'tab');
    bouton.dataset.cible = panneau.dataset.langue;
    bouton.textContent = panneau.dataset.libelle || panneau.dataset.langue;
    bouton.addEventListener('click', () => montrer(panneau));
    barre.appendChild(bouton);
  });

  groupe.classList.add('onglets-actifs');
  groupe.insertBefore(barre, panneaux[0]);
  montrer(panneaux[0]);
}

/* ------------------------------------------------- abandon d'un formulaire */

/**
 * Confirmation avant abandon d'un formulaire modifié (04-back-office §12).
 *
 * L'écoute porte sur `beforeunload` et non sur les clics : elle couvre aussi la
 * fermeture d'onglet et le bouton « précédent », qui sont les deux façons les
 * plus fréquentes de perdre une heure de saisie.
 */
function surveillerLesModifications(formulaire) {
  let modifie = false;

  formulaire.addEventListener('input', () => {
    modifie = true;
  });

  formulaire.addEventListener('submit', () => {
    modifie = false;
  });

  window.addEventListener('beforeunload', (evenement) => {
    if (!modifie) return;
    evenement.preventDefault();
    // Les navigateurs modernes ignorent le texte et affichent le leur.
    evenement.returnValue = '';
  });
}

/* ------------------------------------------------- confirmation de suppression */

/**
 * 06-securite §3 : « Les actions destructrices du back-office demandent une
 * confirmation explicite, jamais par simple lien GET. » La confirmation
 * serveur existe déjà ; celle-ci évite simplement un aller-retour.
 */
function confirmerLesSuppressions(racine) {
  racine.querySelectorAll('form[data-confirmation]').forEach((formulaire) => {
    formulaire.addEventListener('submit', (evenement) => {
      if (!window.confirm(formulaire.dataset.confirmation)) {
        evenement.preventDefault();
      }
    });
  });
}

/* ----------------------------------------------------- slug depuis le titre */

/**
 * Propose un slug tant que le champ n'a pas été touché à la main.
 *
 * Le serveur reste seul maître : il régénère et dédoublonne de toute façon.
 * Ceci ne fait qu'éviter à l'artiste de le taper.
 */
function proposerLeSlug(titre, slug) {
  if (slug.value !== '') return;

  let touche = false;
  slug.addEventListener('input', () => {
    touche = true;
  });

  titre.addEventListener('input', () => {
    if (touche) return;
    slug.value = titre.value
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .replace(/œ/gi, 'oe')
      .replace(/æ/gi, 'ae')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  });
}

/* --------------------------------------------------------------- amorçage */

document.querySelectorAll('[data-onglets-langue]').forEach(activerOnglets);
document.querySelectorAll('form[data-surveiller]').forEach(surveillerLesModifications);
confirmerLesSuppressions(document);

document.querySelectorAll('[data-slug-depuis]').forEach((slug) => {
  const titre = document.getElementById(slug.dataset.slugDepuis);
  if (titre) proposerLeSlug(titre, slug);
});
