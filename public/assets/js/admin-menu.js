/**
 * Rubriques déroulantes du menu d'administration (retours du 2026-09-25).
 *
 * Sans JavaScript, chaque <details> s'ouvre et se ferme seul. Ce module ajoute
 * le confort : ouvrir une rubrique referme les autres, un clic ailleurs ou la
 * touche Échap referme tout.
 */
export function monterMenuAdmin(racine = document) {
  const groupes = [...racine.querySelectorAll('details.admin-groupe')];

  groupes.forEach((groupe) => {
    groupe.addEventListener('toggle', () => {
      if (!groupe.open) return;
      groupes.forEach((autre) => { if (autre !== groupe) autre.open = false; });
    });
  });

  document.addEventListener('click', (evenement) => {
    if (!evenement.target.closest('details.admin-groupe')) {
      groupes.forEach((groupe) => { groupe.open = false; });
    }
  });

  document.addEventListener('keydown', (evenement) => {
    if (evenement.key !== 'Escape') return;
    const ouvert = groupes.find((groupe) => groupe.open);
    if (ouvert) {
      ouvert.open = false;
      ouvert.querySelector('summary')?.focus();
    }
  });
}
