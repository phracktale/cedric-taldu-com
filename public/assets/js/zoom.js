/**
 * Visionneuse plein écran d'une œuvre : zoom et panoramique.
 *
 * Implémentation maison sans bibliothèque (02-front-public §4), fondée sur
 * `transform: scale()` et les Pointer Events.
 *
 * Sans JavaScript, le lien qui porte le visuel ouvre simplement l'image en
 * pleine taille dans un nouvel onglet : c'est le comportement de repli, et il
 * reste correct.
 *
 * La version 2400 px n'est chargée QU'À L'OUVERTURE : elle pèse plusieurs
 * centaines de kilooctets et la plupart des visiteurs ne l'ouvriront jamais.
 */

const ECHELLE_MIN = 1;
const ECHELLE_MAX = 4;

export function initZoom() {
  const declencheurs = document.querySelectorAll('[data-zoom-src]');

  if (declencheurs.length === 0) {
    return;
  }

  declencheurs.forEach((declencheur) => {
    declencheur.addEventListener('click', (evenement) => {
      // Le repli sans JavaScript est un lien : on ne le neutralise qu'une fois
      // certain de pouvoir faire mieux.
      evenement.preventDefault();
      ouvrir(declencheur.dataset.zoomSrc, declencheur.dataset.zoomAlt ?? '');
    });
  });
}

function ouvrir(source, alternative) {
  const precedentFocus = document.activeElement;

  const dialogue = document.createElement('dialog');
  dialogue.className = 'zoom';
  dialogue.setAttribute('aria-modal', 'true');
  dialogue.setAttribute('aria-label', alternative);

  const image = document.createElement('img');
  image.src = source;
  image.alt = alternative;
  image.draggable = false;

  const fermeture = document.createElement('button');
  fermeture.type = 'button';
  fermeture.className = 'zoom-fermer';
  fermeture.textContent = 'Fermer';

  dialogue.append(fermeture, image);
  document.body.append(dialogue);
  dialogue.showModal();
  fermeture.focus();

  const etat = { echelle: 1, x: 0, y: 0, glisse: false, departX: 0, departY: 0 };

  const appliquer = () => {
    image.style.transform = `translate(${etat.x}px, ${etat.y}px) scale(${etat.echelle})`;
    image.style.cursor = etat.echelle > 1 ? 'grab' : 'zoom-in';
  };

  const zoomer = (facteur) => {
    etat.echelle = Math.min(ECHELLE_MAX, Math.max(ECHELLE_MIN, etat.echelle * facteur));

    // Revenu à l'échelle d'origine, l'image se recentre : sans cela, elle
    // resterait décalée hors du cadre.
    if (etat.echelle === ECHELLE_MIN) {
      etat.x = 0;
      etat.y = 0;
    }

    appliquer();
  };

  dialogue.addEventListener('wheel', (evenement) => {
    evenement.preventDefault();
    zoomer(evenement.deltaY < 0 ? 1.15 : 1 / 1.15);
  }, { passive: false });

  image.addEventListener('dblclick', () => zoomer(etat.echelle > 1 ? 1 / etat.echelle : 2));

  image.addEventListener('pointerdown', (evenement) => {
    if (etat.echelle === 1) {
      return;
    }
    etat.glisse = true;
    etat.departX = evenement.clientX - etat.x;
    etat.departY = evenement.clientY - etat.y;
    image.setPointerCapture(evenement.pointerId);
    image.style.cursor = 'grabbing';
  });

  image.addEventListener('pointermove', (evenement) => {
    if (!etat.glisse) {
      return;
    }
    etat.x = evenement.clientX - etat.departX;
    etat.y = evenement.clientY - etat.departY;
    appliquer();
  });

  image.addEventListener('pointerup', (evenement) => {
    etat.glisse = false;
    image.releasePointerCapture(evenement.pointerId);
    appliquer();
  });

  dialogue.addEventListener('keydown', (evenement) => {
    const pas = 40;
    const deplacements = {
      ArrowLeft: [pas, 0],
      ArrowRight: [-pas, 0],
      ArrowUp: [0, pas],
      ArrowDown: [0, -pas],
    };

    if (evenement.key === '+' || evenement.key === '=') {
      evenement.preventDefault();
      zoomer(1.2);
      return;
    }

    if (evenement.key === '-') {
      evenement.preventDefault();
      zoomer(1 / 1.2);
      return;
    }

    const deplacement = deplacements[evenement.key];

    if (deplacement && etat.echelle > 1) {
      evenement.preventDefault();
      etat.x += deplacement[0];
      etat.y += deplacement[1];
      appliquer();
    }
  });

  fermeture.addEventListener('click', () => dialogue.close());

  // Échap est géré nativement par <dialog> : on se contente de rendre le focus.
  dialogue.addEventListener('close', () => {
    dialogue.remove();
    if (precedentFocus instanceof HTMLElement) {
      precedentFocus.focus();
    }
  });

  appliquer();
}
