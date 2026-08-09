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

import { monterEditeursDeBlocs } from './block-editor.js';

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

/* ------------------------------------------------------------- recadrage */

/**
 * Recadrage d'une image de la médiathèque (04-back-office §7).
 *
 * C'est une amélioration au sens du §12 : tracer une zone à la souris exige du
 * JavaScript, et le point focal reste le moyen de cadrer sans lui. Le panneau
 * est donc servi masqué et n'apparaît qu'ici.
 *
 * Aucun pixel n'est envoyé au serveur : la zone part en FRACTIONS (0..1) de
 * l'image, et c'est le serveur seul qui la convertit contre les dimensions
 * réelles de l'original (autorité serveur). Les positions de la zone sont posées
 * via element.style — jamais en attribut inline, la CSP l'interdit.
 */
function activerRecadrage(section) {
  const image = document.querySelector('[data-cropper-image]');
  const form = section.querySelector('[data-cropper-form]');
  if (!image || !form) return;

  const champs = {
    x: form.querySelector('[data-cropper-input="x"]'),
    y: form.querySelector('[data-cropper-input="y"]'),
    w: form.querySelector('[data-cropper-input="w"]'),
    h: form.querySelector('[data-cropper-input="h"]'),
  };
  const valider = form.querySelector('[data-cropper-submit]');
  const annuler = form.querySelector('[data-cropper-reset]');
  if (!champs.x || !champs.y || !champs.w || !champs.h || !valider) return;

  // On encadre l'image pour pouvoir superposer la zone tracée.
  const cadre = document.createElement('div');
  cadre.className = 'recadrage-cadre';
  image.parentNode.insertBefore(cadre, image);
  cadre.appendChild(image);

  const zone = document.createElement('div');
  zone.className = 'recadrage-zone';
  zone.hidden = true;
  cadre.appendChild(zone);

  section.hidden = false;

  let depart = null;

  const point = (evenement) => {
    const rect = image.getBoundingClientRect();
    return {
      x: Math.min(Math.max(evenement.clientX - rect.left, 0), rect.width),
      y: Math.min(Math.max(evenement.clientY - rect.top, 0), rect.height),
      largeur: rect.width,
      hauteur: rect.height,
    };
  };

  const dessiner = (a, b) => {
    const rectangle = {
      gauche: Math.min(a.x, b.x),
      haut: Math.min(a.y, b.y),
      largeur: Math.abs(a.x - b.x),
      hauteur: Math.abs(a.y - b.y),
      cadreLargeur: a.largeur,
      cadreHauteur: a.hauteur,
    };
    zone.hidden = false;
    zone.style.left = `${rectangle.gauche}px`;
    zone.style.top = `${rectangle.haut}px`;
    zone.style.width = `${rectangle.largeur}px`;
    zone.style.height = `${rectangle.hauteur}px`;
    return rectangle;
  };

  const enregistrer = (r) => {
    // Un simple clic n'est pas une zone : sous quelques pixels, on ignore.
    if (r.largeur < 4 || r.hauteur < 4) return false;
    champs.x.value = (r.gauche / r.cadreLargeur).toFixed(5);
    champs.y.value = (r.haut / r.cadreHauteur).toFixed(5);
    champs.w.value = (r.largeur / r.cadreLargeur).toFixed(5);
    champs.h.value = (r.hauteur / r.cadreHauteur).toFixed(5);
    return true;
  };

  const reinitialiser = () => {
    zone.hidden = true;
    valider.disabled = true;
    ['x', 'y', 'w', 'h'].forEach((clef) => {
      champs[clef].value = '';
    });
  };

  cadre.addEventListener('pointerdown', (evenement) => {
    evenement.preventDefault();
    cadre.setPointerCapture(evenement.pointerId);
    depart = point(evenement);
    zone.hidden = true;
    valider.disabled = true;
  });

  cadre.addEventListener('pointermove', (evenement) => {
    if (depart) dessiner(depart, point(evenement));
  });

  cadre.addEventListener('pointerup', (evenement) => {
    if (!depart) return;
    const rectangle = dessiner(depart, point(evenement));
    depart = null;
    valider.disabled = !enregistrer(rectangle);
    if (valider.disabled) zone.hidden = true;
  });

  if (annuler) annuler.addEventListener('click', reinitialiser);
}

/* --------------------------------------------------------------- amorçage */

document.querySelectorAll('[data-onglets-langue]').forEach(activerOnglets);
document.querySelectorAll('form[data-surveiller]').forEach(surveillerLesModifications);
confirmerLesSuppressions(document);
document.querySelectorAll('[data-cropper]').forEach(activerRecadrage);
monterEditeursDeBlocs(document);

document.querySelectorAll('[data-slug-depuis]').forEach((slug) => {
  const titre = document.getElementById(slug.dataset.slugDepuis);
  if (titre) proposerLeSlug(titre, slug);
});
