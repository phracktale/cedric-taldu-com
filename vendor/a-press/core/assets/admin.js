/*
 * Administration A-Press : confort des listes de l'éditeur.
 *
 * Sans ce script, tout fonctionne : chaque liste montre une ligne vide de plus
 * pour ajouter, et une ligne vidée est retirée à l'enregistrement. Avec lui :
 * « Ajouter une ligne », « Retirer », « Monter » et « Descendre », et les noms
 * de champs renumérotés pour que l'ordre envoyé soit l'ordre affiché.
 *
 * Module chargé par <script type="module" src> : aucun gestionnaire en ligne,
 * compatible avec la CSP stricte (script-src 'self').
 */

const SEP = '--';

/** Renumérote les champs d'une liste : prefixe--INDEX--champ. */
function renumber(list) {
  const rows = [...list.children].filter((el) => el.matches('[data-ligne]'));
  rows.forEach((row, index) => {
    row.querySelectorAll('[name]').forEach((field) => {
      const prefix = list.dataset.prefix;
      if (!prefix || !field.name.startsWith(prefix + SEP)) return;
      const rest = field.name.slice(prefix.length + SEP.length);
      const cut = rest.indexOf(SEP);
      if (cut < 0) return;
      field.name = prefix + SEP + index + rest.slice(cut);
      if (field.id) {
        const label = row.querySelector(`label[for="${CSS.escape(field.id)}"]`);
        field.id = 'champ-' + field.name.replaceAll(SEP, '-');
        if (label) label.htmlFor = field.id;
      }
    });
  });
}

function button(text, action) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'lien-bouton';
  b.textContent = text;
  b.dataset.action = action;
  return b;
}

function equip(list) {
  const first = list.querySelector(':scope > [data-ligne] [name]');
  if (!first) return;
  // Le préfixe est le nom du premier champ privé de « --0--champ ».
  const match = first.name.match(/^(.*)--\d+--[^-]+(?:--.*)?$/);
  list.dataset.prefix = match ? match[1] : '';

  for (const row of list.querySelectorAll(':scope > [data-ligne]')) {
    const tools = document.createElement('div');
    tools.className = 'ligne-outils';
    tools.append(button('Monter', 'up'), button('Descendre', 'down'), button('Retirer', 'remove'));
    row.prepend(tools);
  }

  const template = list.querySelector(':scope > [data-ligne]:last-child');
  const add = button('Ajouter une ligne', 'add');
  add.classList.add('bouton', 'bouton--secondaire');
  list.after(add);

  add.addEventListener('click', () => {
    const copy = template.cloneNode(true);
    copy.querySelectorAll('input:not([type="hidden"]), textarea').forEach((f) => { f.value = ''; f.checked = false; });
    copy.querySelectorAll('select').forEach((f) => { f.selectedIndex = 0; });
    list.append(copy);
    renumber(list);
    copy.querySelector('input:not([type="hidden"]), textarea, select')?.focus();
  });

  list.addEventListener('click', (event) => {
    const target = event.target.closest('button[data-action]');
    if (!target || target.closest('[data-liste]') !== list) return;
    const row = target.closest('[data-ligne]');
    if (target.dataset.action === 'remove') {
      if (list.querySelectorAll(':scope > [data-ligne]').length > 1) row.remove();
      else row.querySelectorAll('input:not([type="hidden"]), textarea').forEach((f) => { f.value = ''; });
    }
    if (target.dataset.action === 'up' && row.previousElementSibling) row.previousElementSibling.before(row);
    if (target.dataset.action === 'down' && row.nextElementSibling) row.nextElementSibling.after(row);
    renumber(list);
    target.focus();
  });
}

document.querySelectorAll('[data-liste]').forEach(equip);
