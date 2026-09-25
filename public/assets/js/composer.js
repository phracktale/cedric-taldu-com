/**
 * Compositeur par glisser-déposer (retours du 2026-09-25).
 *
 * Une palette d'éléments disponibles et une ou plusieurs zones de composition :
 * menus du site, sections de l'accueil, modèles de contenu. L'ordre est celui
 * des éléments dans la zone ; chaque changement réécrit le champ caché de la
 * zone (JSON), qui est ce que le formulaire poste.
 *
 * Structure attendue (rendue par le serveur) :
 *   [data-composer]
 *     [data-composer-palette]  … [data-composer-source] (data-item, data-label)
 *     [data-composer-link]     facultatif : libellé FR/EN + adresse → lien direct
 *     [data-composer-target]   facultatif : <select> de la zone cible des ajouts
 *     [data-composer-zone]     data-name, data-labels (éditer les libellés), data-unique
 *       [data-composer-entry]  (data-item) — entrées existantes
 *     input[type=hidden][name=…] — valeur postée
 *
 * Accessibilité : le glisser-déposer HTML5 ne marche ni au clavier ni au
 * tactile ; chaque source a un bouton « Ajouter », chaque entrée ↑ ↓ ✕.
 * Aucun innerHTML de données : createElement + textContent (CSP stricte).
 */

export function monterCompositeurs(racine = document) {
  racine.querySelectorAll('[data-composer]').forEach(monter);
}

function monter(racine) {
  const zones = [...racine.querySelectorAll('[data-composer-zone]')];
  const cible = racine.querySelector('[data-composer-target]');
  let glisse = null; // { element, depuisPalette, item, label }

  const zoneCible = () => zones.find((z) => z.dataset.name === cible?.value) ?? zones[0];

  const ecrire = (zone) => {
    const champ = racine.querySelector(`input[type="hidden"][name="${zone.dataset.name}"]`);
    const items = [...zone.querySelectorAll(':scope > [data-composer-entry]')].map((li) => JSON.parse(li.dataset.item));
    if (champ) champ.value = JSON.stringify(items);
    zone.classList.toggle('composer-zone--vide', items.length === 0);
    majPalette();
  };

  // Une zone « unique » (accueil, modèles) n'accepte chaque élément qu'une fois :
  // la source correspondante est alors grisée.
  const majPalette = () => {
    racine.querySelectorAll('[data-composer-source]').forEach((source) => {
      const donnee = JSON.parse(source.dataset.item);
      const cle = cleDe(donnee);
      // Un « Nouveau bloc » crée un bloc à chaque ajout : jamais grisé.
      const deja = donnee.type !== 'new' && zones.some((z) => z.dataset.unique !== undefined
        && [...z.querySelectorAll('[data-composer-entry]')].some((li) => cleDe(JSON.parse(li.dataset.item)) === cle));
      source.classList.toggle('composer-source--prise', deja);
      source.querySelector('button')?.toggleAttribute('disabled', deja);
      source.draggable = !deja;
    });
  };

  const brancherEntree = (li, zone) => {
    li.draggable = true;
    li.addEventListener('dragstart', (e) => {
      glisse = { element: li, depuisPalette: false };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', li.dataset.item);
      li.classList.add('composer-entree--glisse');
    });
    li.addEventListener('dragend', () => { li.classList.remove('composer-entree--glisse'); glisse = null; });

    li.querySelector('[data-composer-up]')?.addEventListener('click', () => {
      if (li.previousElementSibling) li.parentElement.insertBefore(li, li.previousElementSibling);
      ecrire(zone); li.querySelector('[data-composer-up]')?.focus();
    });
    li.querySelector('[data-composer-down]')?.addEventListener('click', () => {
      if (li.nextElementSibling) li.parentElement.insertBefore(li.nextElementSibling, li);
      ecrire(zone); li.querySelector('[data-composer-down]')?.focus();
    });
    li.querySelector('[data-composer-remove]')?.addEventListener('click', () => { li.remove(); ecrire(zone); });

    li.querySelectorAll('[data-composer-label]').forEach((champ) => {
      champ.addEventListener('input', () => {
        const item = JSON.parse(li.dataset.item);
        item.labels = { fr: '', en: '', ...(item.labels || {}) };
        item.labels[champ.dataset.composerLabel] = champ.value;
        li.dataset.item = JSON.stringify(item);
        ecrire(zone);
      });
    });
  };

  const creerEntree = (item, label, zone) => {
    const li = document.createElement('li');
    li.className = 'composer-entree';
    li.dataset.composerEntry = '';
    li.dataset.item = JSON.stringify(item);

    const poignee = document.createElement('span');
    poignee.className = 'composer-poignee';
    poignee.setAttribute('aria-hidden', 'true');
    poignee.textContent = '⠿';

    const nom = document.createElement('span');
    nom.className = 'composer-nom';
    nom.textContent = label;

    li.append(poignee, nom);

    if (zone.dataset.labels !== undefined) {
      const details = document.createElement('details');
      details.className = 'composer-libelles';
      const resume = document.createElement('summary');
      resume.textContent = 'Libellés';
      details.appendChild(resume);
      ['fr', 'en'].forEach((langue) => {
        const etiquette = document.createElement('label');
        etiquette.textContent = langue === 'fr' ? 'Français ' : 'Anglais ';
        const champ = document.createElement('input');
        champ.type = 'text';
        champ.maxLength = 60;
        champ.dataset.composerLabel = langue;
        champ.value = item.labels?.[langue] ?? '';
        champ.placeholder = langue === 'fr' ? label : '';
        etiquette.appendChild(champ);
        details.appendChild(etiquette);
      });
      li.appendChild(details);
    }

    const actions = document.createElement('span');
    actions.className = 'composer-actions';
    [['↑', 'Monter', 'composerUp'], ['↓', 'Descendre', 'composerDown'], ['✕', 'Retirer', 'composerRemove']]
      .forEach(([texte, aria, cle]) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'eb-btn';
        b.textContent = texte;
        b.setAttribute('aria-label', `${aria} « ${label} »`);
        b.dataset[cle] = '';
        actions.appendChild(b);
      });
    li.appendChild(actions);

    brancherEntree(li, zone);
    return li;
  };

  // Place un élément glissé à l'endroit du pointeur dans la zone.
  const avant = (zone, y) => [...zone.querySelectorAll(':scope > [data-composer-entry]:not(.composer-entree--glisse)')]
    .find((li) => { const r = li.getBoundingClientRect(); return y < r.top + r.height / 2; }) ?? null;

  zones.forEach((zone) => {
    zone.querySelectorAll(':scope > [data-composer-entry]').forEach((li) => brancherEntree(li, zone));

    zone.addEventListener('dragover', (e) => {
      if (!glisse) return;
      e.preventDefault();
      zone.classList.add('composer-zone--survol');
      if (!glisse.depuisPalette) zone.insertBefore(glisse.element, avant(zone, e.clientY));
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('composer-zone--survol'));
    zone.addEventListener('drop', (e) => {
      e.preventDefault();
      zone.classList.remove('composer-zone--survol');
      if (!glisse) return;
      if (glisse.depuisPalette) {
        zone.insertBefore(creerEntree(glisse.item, glisse.label, zone), avant(zone, e.clientY));
      }
      zones.forEach(ecrire);
    });
  });

  racine.querySelectorAll('[data-composer-source]').forEach((source) => {
    const item = () => JSON.parse(source.dataset.item);
    source.addEventListener('dragstart', (e) => {
      glisse = { depuisPalette: true, item: item(), label: source.dataset.label };
      e.dataTransfer.effectAllowed = 'copy';
      e.dataTransfer.setData('text/plain', source.dataset.label);
    });
    source.addEventListener('dragend', () => { glisse = null; });
    source.querySelector('button')?.addEventListener('click', () => {
      const zone = zoneCible();
      zone.appendChild(creerEntree(item(), source.dataset.label, zone));
      ecrire(zone);
    });
  });

  // Lien direct : libellés et adresse saisis, ajoutés à la zone cible.
  const lien = racine.querySelector('[data-composer-link]');
  lien?.querySelector('button')?.addEventListener('click', () => {
    const valeur = (nom) => lien.querySelector(`[name="${nom}"]`)?.value.trim() ?? '';
    const fr = valeur('lien_fr');
    const url = valeur('lien_url');
    const erreur = lien.querySelector('[data-composer-link-error]');
    if (fr === '' || !/^(\/(?!\/)|https:\/\/)/.test(url)) {
      if (erreur) erreur.hidden = false;
      return;
    }
    if (erreur) erreur.hidden = true;
    const zone = zoneCible();
    zone.appendChild(creerEntree({ type: 'link', url, labels: { fr, en: valeur('lien_en') } }, fr, zone));
    lien.querySelectorAll('input').forEach((champ) => { champ.value = ''; });
    ecrire(zone);
  });

  zones.forEach(ecrire);
}

function cleDe(item) {
  return item.ref != null ? `${item.type}:${item.ref}` : String(item.type ?? item.section ?? '');
}
