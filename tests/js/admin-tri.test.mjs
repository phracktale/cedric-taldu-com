/**
 * Glisser-déposer de la liste des œuvres (demande du 2026-10-12) : le calcul
 * du nouvel ordre est une fonction pure, le reste du module n'est que du DOM.
 *
 * Lancé par tests/Unit/Assets/AdminTriTest.php (node --test).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deplacer } from '../../public/assets/js/admin-tri-ordre.js';

test('déposer une ligne AVANT une autre', () => {
  assert.deepEqual(deplacer(['1', '2', '3', '4'], '4', '2', false), ['1', '4', '2', '3']);
});

test('déposer une ligne APRÈS une autre', () => {
  assert.deepEqual(deplacer(['1', '2', '3', '4'], '1', '3', true), ['2', '3', '1', '4']);
});

test('déposer une ligne sur elle-même ne change rien', () => {
  assert.deepEqual(deplacer(['1', '2', '3'], '2', '2', true), ['1', '2', '3']);
});

test('un identifiant inconnu ne change rien', () => {
  assert.deepEqual(deplacer(['1', '2', '3'], '9', '2', false), ['1', '2', '3']);
  assert.deepEqual(deplacer(['1', '2', '3'], '1', '9', false), ['1', '2', '3']);
});

test("la liste d'origine n'est pas modifiée", () => {
  const ids = ['1', '2', '3'];
  deplacer(ids, '3', '1', false);
  assert.deepEqual(ids, ['1', '2', '3']);
});
