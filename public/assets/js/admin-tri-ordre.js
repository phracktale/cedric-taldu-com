/**
 * Calcul du nouvel ordre après un glisser-déposer (demande du 2026-10-12).
 *
 * Fonction pure, sans DOM : testée par tests/js/admin-tri.test.mjs.
 */

/**
 * Déplace `deplace` juste avant `cible` — ou juste après si `apres` — et rend
 * une NOUVELLE liste. Identifiant inconnu, ou ligne déposée sur elle-même :
 * l'ordre est rendu inchangé.
 */
export function deplacer(ids, deplace, cible, apres) {
  if (deplace === cible || !ids.includes(deplace) || !ids.includes(cible)) {
    return [...ids];
  }

  const reste = ids.filter((id) => id !== deplace);
  const position = reste.indexOf(cible) + (apres ? 1 : 0);

  return [...reste.slice(0, position), deplace, ...reste.slice(position)];
}
