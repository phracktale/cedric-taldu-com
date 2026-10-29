/**
 * Glisser-déposer des lignes d'une liste d'administration (demande du
 * 2026-10-12) : l'ordre libre de la page « Toutes les œuvres », ou l'ordre
 * d'une galerie.
 *
 * Structure attendue (rendue par le serveur) :
 *   form[data-tri-form]      champs cachés _token, portee, ordre
 *   table[data-tri]
 *     tr[data-tri-id]        une ligne par élément
 *       [data-tri-poignee]   la poignée : seule prise pour déplacer la ligne
 *
 * La ligne lâchée, le module écrit le nouvel ordre dans le champ `ordre` et
 * poste le formulaire : le serveur enregistre et réaffiche la liste.
 *
 * Amélioration seulement : le glisser-déposer HTML5 ne marche ni au clavier ni
 * au doigt. Les flèches ↑ ↓ de chaque ligne, de simples formulaires, restent
 * le moyen qui marche partout.
 */

import { deplacer } from './admin-tri-ordre.js?v=20261029';

export function monterTris(racine = document) {
  const formulaire = racine.querySelector('[data-tri-form]');

  if (formulaire) {
    racine.querySelectorAll('table[data-tri]').forEach((tableau) => monter(tableau, formulaire));
  }
}

function monter(tableau, formulaire) {
  const lignes = () => [...tableau.querySelectorAll('tr[data-tri-id]')];
  let glissee = null;

  const effacerReperes = () => {
    lignes().forEach((ligne) => ligne.classList.remove('tri-ligne--avant', 'tri-ligne--apres'));
  };

  // Moitié basse de la ligne survolée : on déposera après elle.
  const apres = (ligne, evenement) => {
    const cadre = ligne.getBoundingClientRect();
    return evenement.clientY > cadre.top + cadre.height / 2;
  };

  lignes().forEach((ligne) => {
    const poignee = ligne.querySelector('[data-tri-poignee]');

    // La ligne ne devient déplaçable que saisie par sa poignée : le texte du
    // tableau reste sélectionnable, les liens cliquables.
    poignee?.addEventListener('pointerdown', () => { ligne.draggable = true; });
    poignee?.addEventListener('pointerup', () => { ligne.draggable = false; });

    ligne.addEventListener('dragstart', (evenement) => {
      glissee = ligne;
      evenement.dataTransfer.effectAllowed = 'move';
      evenement.dataTransfer.setData('text/plain', ligne.dataset.triId);
      ligne.classList.add('tri-ligne--glisse');
    });

    ligne.addEventListener('dragend', () => {
      ligne.draggable = false;
      ligne.classList.remove('tri-ligne--glisse');
      effacerReperes();
      glissee = null;
    });

    ligne.addEventListener('dragover', (evenement) => {
      if (!glissee || glissee === ligne) {
        return;
      }
      evenement.preventDefault();
      evenement.dataTransfer.dropEffect = 'move';
      effacerReperes();
      ligne.classList.add(apres(ligne, evenement) ? 'tri-ligne--apres' : 'tri-ligne--avant');
    });

    ligne.addEventListener('drop', (evenement) => {
      if (!glissee || glissee === ligne) {
        return;
      }
      evenement.preventDefault();

      const ordre = deplacer(
        lignes().map((l) => l.dataset.triId),
        glissee.dataset.triId,
        ligne.dataset.triId,
        apres(ligne, evenement),
      );

      formulaire.elements.ordre.value = ordre.join(',');
      formulaire.submit();
    });
  });
}
