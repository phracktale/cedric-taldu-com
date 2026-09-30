/**
 * Visionneuse plein écran d'une œuvre : un explorateur (demande du
 * 2026-09-30, sur le modèle de vanilla-js-wheel-zoom, sans la bibliothèque —
 * 02-front-public §4 : implémentation maison).
 *
 * - la molette zoome VERS le curseur, deux doigts zooment autour de leur milieu ;
 * - on fait glisser l'image, qui reste toujours tenue à l'écran ;
 * - boutons +, − et « Ajuster », double-clic, clavier (+, −, 0, flèches).
 *
 * Sans JavaScript, le lien qui porte le visuel ouvre simplement l'image dans un
 * nouvel onglet : c'est le repli, et il reste correct. L'image du zoom (2000 px
 * au plus grand côté) n'est chargée QU'À L'OUVERTURE.
 *
 * Netteté (retours du 2026-09-28) : à l'ouverture l'image est seulement réduite
 * pour tenir à l'écran, jamais agrandie ; le zoom s'arrête à la résolution
 * native — un pixel de l'image pour un pixel de l'écran.
 *
 * La géométrie, pure et testée, vit dans zoom-geometrie.js.
 */

import {
  ajuster, echelleMax, initial, borner, zoomerVers, ecart, milieu,
} from './zoom-geometrie.js';

const LIBELLES = {
  fr: { fermer: 'Fermer', plus: 'Zoomer', moins: 'Dézoomer', ajuster: 'Ajuster' },
  en: { fermer: 'Close', plus: 'Zoom in', moins: 'Zoom out', ajuster: 'Fit' },
};

export function initZoom() {
  const declencheurs = document.querySelectorAll('[data-zoom-src]');

  declencheurs.forEach((declencheur) => {
    declencheur.addEventListener('click', (evenement) => {
      // Le repli sans JavaScript est un lien : on ne le neutralise qu'une fois
      // certain de pouvoir faire mieux.
      evenement.preventDefault();
      ouvrir(declencheur.dataset.zoomSrc, declencheur.dataset.zoomAlt ?? '');
    });
  });
}

function bouton(classe, texte, libelle) {
  const element = document.createElement('button');
  element.type = 'button';
  element.className = classe;
  element.textContent = texte;
  if (libelle) {
    element.setAttribute('aria-label', libelle);
    element.title = libelle;
  }

  return element;
}

function ouvrir(source, alternative) {
  const precedentFocus = document.activeElement;
  const libelles = LIBELLES[document.documentElement.lang] ?? LIBELLES.fr;

  const dialogue = document.createElement('dialog');
  dialogue.className = 'zoom';
  dialogue.setAttribute('aria-modal', 'true');
  dialogue.setAttribute('aria-label', alternative);

  const image = document.createElement('img');
  image.alt = alternative;
  image.draggable = false;

  const fermeture = bouton('zoom-fermer', libelles.fermer);
  const outils = document.createElement('div');
  outils.className = 'zoom-outils';
  const plus = bouton('zoom-outil', '+', libelles.plus);
  const moins = bouton('zoom-outil', '−', libelles.moins);
  const reinitialiser = bouton('zoom-outil zoom-outil--texte', libelles.ajuster);
  outils.append(moins, reinitialiser, plus);

  dialogue.append(image, fermeture, outils);
  document.body.append(dialogue);
  dialogue.showModal();
  fermeture.focus();

  let vue = { largeur: dialogue.clientWidth, hauteur: dialogue.clientHeight };
  let taille = { largeur: 1, hauteur: 1 };
  let maximum = 1;
  let etat = { echelle: 1, x: 0, y: 0 };
  const doigts = new Map();
  let geste = null;

  const appliquer = () => {
    image.style.width = `${taille.largeur}px`;
    image.style.height = `${taille.hauteur}px`;
    image.style.transform = `translate(${etat.x}px, ${etat.y}px) scale(${etat.echelle})`;
    dialogue.classList.toggle('zoom--agrandi', etat.echelle > 1);
    plus.disabled = etat.echelle >= maximum;
    moins.disabled = etat.echelle <= 1;
  };

  const mesurer = () => {
    vue = { largeur: dialogue.clientWidth, hauteur: dialogue.clientHeight };
    if (image.naturalWidth > 0) {
      const echelleAvant = etat.echelle;
      taille = ajuster(image.naturalWidth, image.naturalHeight, vue);
      maximum = echelleMax(image.naturalWidth, taille);
      etat = borner({ ...etat, echelle: Math.min(maximum, echelleAvant) }, taille, vue);
    }
    appliquer();
  };

  const zoomer = (facteur, cx = vue.largeur / 2, cy = vue.hauteur / 2) => {
    etat = zoomerVers(etat, facteur, cx, cy, taille, vue, maximum);
    appliquer();
  };

  const point = (evenement) => {
    const cadre = dialogue.getBoundingClientRect();
    return { x: evenement.clientX - cadre.left, y: evenement.clientY - cadre.top };
  };

  image.addEventListener('load', () => {
    taille = ajuster(image.naturalWidth, image.naturalHeight, vue);
    maximum = echelleMax(image.naturalWidth, taille);
    etat = initial(taille, vue);
    appliquer();
    dialogue.classList.add('zoom--pret');
  });
  image.src = source;

  dialogue.addEventListener('wheel', (evenement) => {
    evenement.preventDefault();
    const { x, y } = point(evenement);
    // Défilement fin (pavé tactile) comme cran de molette : un facteur continu.
    zoomer(Math.exp(-evenement.deltaY * 0.0015), x, y);
  }, { passive: false });

  image.addEventListener('dblclick', (evenement) => {
    const { x, y } = point(evenement);
    zoomer(etat.echelle >= maximum ? 1 / etat.echelle : 2, x, y);
  });

  dialogue.addEventListener('pointerdown', (evenement) => {
    if (evenement.target !== image) {
      return;
    }
    image.setPointerCapture(evenement.pointerId);
    doigts.set(evenement.pointerId, point(evenement));
    dialogue.classList.add('zoom--glisse');

    if (doigts.size === 2) {
      const [a, b] = [...doigts.values()];
      geste = { type: 'pincement', ecart: ecart(a, b) };
    } else {
      geste = { type: 'glisse', depart: point(evenement), x: etat.x, y: etat.y };
    }
  });

  dialogue.addEventListener('pointermove', (evenement) => {
    if (!doigts.has(evenement.pointerId) || geste === null) {
      return;
    }
    doigts.set(evenement.pointerId, point(evenement));

    if (geste.type === 'pincement' && doigts.size === 2) {
      const [a, b] = [...doigts.values()];
      const nouvelEcart = ecart(a, b);
      const centre = milieu(a, b);
      if (geste.ecart > 0) {
        zoomer(nouvelEcart / geste.ecart, centre.x, centre.y);
      }
      geste.ecart = nouvelEcart;
    } else if (geste.type === 'glisse') {
      const ici = point(evenement);
      etat = borner({
        echelle: etat.echelle,
        x: geste.x + ici.x - geste.depart.x,
        y: geste.y + ici.y - geste.depart.y,
      }, taille, vue);
      appliquer();
    }
  });

  const relacher = (evenement) => {
    doigts.delete(evenement.pointerId);
    if (doigts.size === 1) {
      // Un doigt se lève d'un pincement : l'autre reprend le glissé.
      const [reste] = [...doigts.values()];
      geste = { type: 'glisse', depart: reste, x: etat.x, y: etat.y };
    } else if (doigts.size === 0) {
      geste = null;
      dialogue.classList.remove('zoom--glisse');
    }
  };
  dialogue.addEventListener('pointerup', relacher);
  dialogue.addEventListener('pointercancel', relacher);

  plus.addEventListener('click', () => zoomer(1.5));
  moins.addEventListener('click', () => zoomer(1 / 1.5));
  reinitialiser.addEventListener('click', () => {
    etat = initial(taille, vue);
    appliquer();
  });

  dialogue.addEventListener('keydown', (evenement) => {
    const pas = 60;
    const deplacements = {
      ArrowLeft: [pas, 0],
      ArrowRight: [-pas, 0],
      ArrowUp: [0, pas],
      ArrowDown: [0, -pas],
    };

    if (evenement.key === '+' || evenement.key === '=') {
      evenement.preventDefault();
      zoomer(1.25);
    } else if (evenement.key === '-') {
      evenement.preventDefault();
      zoomer(1 / 1.25);
    } else if (evenement.key === '0') {
      evenement.preventDefault();
      etat = initial(taille, vue);
      appliquer();
    } else if (deplacements[evenement.key] && etat.echelle > 1) {
      evenement.preventDefault();
      const [dx, dy] = deplacements[evenement.key];
      etat = borner({ echelle: etat.echelle, x: etat.x + dx, y: etat.y + dy }, taille, vue);
      appliquer();
    }
  });

  window.addEventListener('resize', mesurer);
  fermeture.addEventListener('click', () => dialogue.close());

  // Échap est géré nativement par <dialog> : on se contente de rendre le focus.
  dialogue.addEventListener('close', () => {
    window.removeEventListener('resize', mesurer);
    dialogue.remove();
    if (precedentFocus instanceof HTMLElement) {
      precedentFocus.focus();
    }
  });
}
