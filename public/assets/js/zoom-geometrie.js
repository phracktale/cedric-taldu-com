/**
 * Géométrie de la visionneuse d'œuvre (demande du 2026-09-30).
 *
 * Fonctions pures, sans DOM : testées par tests/js/zoom-geometrie.test.mjs.
 *
 * Repère : l'image, à sa taille « ajustée » (tenant à l'écran), est placée à
 * (x, y) dans la vue puis agrandie par `echelle` depuis son coin haut gauche —
 * `transform: translate(x, y) scale(echelle)` avec `transform-origin: 0 0`.
 * Un point (px, py) de l'image ajustée est donc à l'écran en
 * (x + px * echelle, y + py * echelle).
 */

/** Taille à l'ouverture : réduite pour tenir à l'écran, jamais agrandie. */
export function ajuster(naturelLargeur, naturelHauteur, vue) {
  const facteur = Math.min(1, vue.largeur / naturelLargeur, vue.hauteur / naturelHauteur);

  return {
    largeur: Math.round(naturelLargeur * facteur * 10) / 10,
    hauteur: Math.round(naturelHauteur * facteur * 10) / 10,
  };
}

/** Échelle maximale : un pixel de l'image pour un pixel de l'écran. */
export function echelleMax(naturelLargeur, taille) {
  return Math.max(1, naturelLargeur / taille.largeur);
}

/** État à l'ouverture : échelle 1, image centrée. */
export function initial(taille, vue) {
  return borner({ echelle: 1, x: 0, y: 0 }, taille, vue);
}

/**
 * Tient l'image à l'écran : agrandie, elle ne laisse jamais de vide sur un
 * bord ; plus petite que la vue sur un axe, elle y reste centrée.
 */
export function borner(etat, taille, vue) {
  const axe = (position, dimension, visible) => {
    const agrandie = dimension * etat.echelle;
    if (agrandie <= visible) {
      return (visible - agrandie) / 2;
    }

    return Math.min(0, Math.max(visible - agrandie, position));
  };

  return {
    echelle: etat.echelle,
    x: axe(etat.x, taille.largeur, vue.largeur),
    y: axe(etat.y, taille.hauteur, vue.hauteur),
  };
}

/**
 * Zoom d'un facteur VERS le point (cx, cy) de l'écran : ce point de l'image
 * reste sous le curseur (ou entre les deux doigts).
 */
export function zoomerVers(etat, facteur, cx, cy, taille, vue, maximum) {
  const echelle = Math.min(maximum, Math.max(1, etat.echelle * facteur));
  const px = (cx - etat.x) / etat.echelle;
  const py = (cy - etat.y) / etat.echelle;

  return borner({ echelle, x: cx - px * echelle, y: cy - py * echelle }, taille, vue);
}

/** Distance entre deux doigts. */
export function ecart(a, b) {
  return Math.hypot(b.x - a.x, b.y - a.y);
}

/** Milieu de deux doigts. */
export function milieu(a, b) {
  return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
}
