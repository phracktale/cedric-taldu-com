/**
 * Géométrie de la visionneuse d'œuvre (demande du 2026-09-30) : un
 * explorateur où la molette zoome VERS le curseur, où l'on fait glisser
 * l'image sans jamais la perdre hors de l'écran, et où deux doigts zooment.
 *
 * Lancé par tests/Unit/Assets/ZoomGeometrieTest.php (node --test).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  ajuster, echelleMax, initial, borner, zoomerVers, ecart, milieu,
} from '../../public/assets/js/zoom-geometrie.js';

const vue = { largeur: 1000, hauteur: 800 };

test("l'image est réduite pour tenir à l'écran, jamais agrandie", () => {
  assert.deepEqual(ajuster(2000, 1000, vue), { largeur: 1000, hauteur: 500 });
  assert.deepEqual(ajuster(1333, 2000, vue), { largeur: 533.2, hauteur: 800 });
  assert.deepEqual(ajuster(400, 300, vue), { largeur: 400, hauteur: 300 });
});

test("le zoom s'arrête à la résolution native (les points restent nets)", () => {
  assert.equal(echelleMax(2000, { largeur: 1000, hauteur: 500 }), 2);
  assert.equal(echelleMax(400, { largeur: 400, hauteur: 300 }), 1);
});

test("à l'ouverture, l'image est centrée à l'échelle 1", () => {
  assert.deepEqual(initial({ largeur: 1000, hauteur: 500 }, vue), { echelle: 1, x: 0, y: 150 });
});

test('la molette zoome vers le curseur : le point visé ne bouge pas', () => {
  const taille = { largeur: 1000, hauteur: 500 };
  const etat = initial(taille, vue);
  // Curseur sur le point (250, 400) de l'écran, soit (250, 250) dans l'image —
  // assez loin des bords pour que l'image n'ait pas à être retenue.
  const apres = zoomerVers(etat, 2, 250, 400, taille, vue, 2);

  assert.equal(apres.echelle, 2);
  assert.equal(apres.x + 250 * apres.echelle, 250);
  assert.equal(apres.y + 250 * apres.echelle, 400);
});

test("le zoom est borné entre l'échelle 1 et la résolution native", () => {
  const taille = { largeur: 1000, hauteur: 500 };
  const etat = initial(taille, vue);

  assert.equal(zoomerVers(etat, 10, 500, 400, taille, vue, 2).echelle, 2);
  assert.equal(zoomerVers(etat, 0.1, 500, 400, taille, vue, 2).echelle, 1);
});

test("l'image agrandie ne peut pas quitter l'écran", () => {
  const taille = { largeur: 1000, hauteur: 500 };
  // Échelle 2 : 2000 x 1000 dans une vue de 1000 x 800.
  const tropADroite = borner({ echelle: 2, x: 300, y: 50 }, taille, vue);
  assert.deepEqual(tropADroite, { echelle: 2, x: 0, y: 0 });

  const tropAGauche = borner({ echelle: 2, x: -5000, y: -5000 }, taille, vue);
  assert.deepEqual(tropAGauche, { echelle: 2, x: -1000, y: -200 });
});

test("plus petite que l'écran sur un axe, l'image reste centrée sur cet axe", () => {
  const taille = { largeur: 400, hauteur: 300 };
  assert.deepEqual(borner({ echelle: 1, x: -80, y: 900 }, taille, vue), { echelle: 1, x: 300, y: 250 });
});

test('le pincement se mesure entre deux doigts, autour de leur milieu', () => {
  assert.equal(ecart({ x: 0, y: 0 }, { x: 30, y: 40 }), 50);
  assert.deepEqual(milieu({ x: 0, y: 0 }, { x: 30, y: 40 }), { x: 15, y: 20 });
});
