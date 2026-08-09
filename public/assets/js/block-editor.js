/**
 * Éditeur de blocs du back-office (04-back-office, audit éditorial).
 *
 * Amélioration progressive : le champ `[data-block-editor]` est un <textarea>
 * portant le JSON des blocs (format editor-core / FatPlant). Sans JavaScript,
 * l'artiste édite ce JSON à la main ; avec, ce module masque le textarea et
 * bâtit une interface visuelle par-dessus, qu'il resynchronise dans le textarea
 * à chaque changement. C'est donc le textarea — jamais le DOM de l'éditeur — qui
 * est posté.
 *
 * Zéro dépendance, zéro innerHTML de données : tout par createElement +
 * textContent/value, pour rester sous la CSP stricte et sans faille d'injection.
 */

/** Point d'entrée : monte l'éditeur sur chaque textarea marqué. */
export function monterEditeursDeBlocs(racine = document) {
  racine.querySelectorAll('[data-block-editor]').forEach(monter);
}

function monter(textarea) {
  let catalogue = {};
  try {
    catalogue = JSON.parse(textarea.dataset.catalog || '{}');
  } catch { catalogue = {}; }

  let blocs = analyser(textarea.value);

  textarea.hidden = true;
  const racine = document.createElement('div');
  racine.className = 'editeur-blocs';
  textarea.insertAdjacentElement('afterend', racine);

  // Écrit le JSON courant dans le textarea (la source de vérité postée).
  const ecrire = () => { textarea.value = JSON.stringify(blocs); };
  // Reconstruit toute l'UI (après un changement de STRUCTURE).
  const redessiner = () => { racine.replaceChildren(vueListe(blocs)); ecrire(); };

  /** Vue d'une liste de blocs (racine ou enfants d'un conteneur). */
  function vueListe(liste) {
    const bloc = document.createElement('div');
    bloc.className = 'eb-liste';

    liste.forEach((donnee, index) => bloc.appendChild(vueBloc(donnee, liste, index)));
    bloc.appendChild(barreAjout(liste));

    return bloc;
  }

  /** Carte d'un bloc : entête (déplacer/supprimer) + champs de props + enfants. */
  function vueBloc(donnee, liste, index) {
    const def = catalogue[donnee.type] || { label: donnee.type, schema: {}, allowChildren: false };

    const carte = document.createElement('div');
    carte.className = 'eb-bloc';

    const entete = document.createElement('div');
    entete.className = 'eb-entete';
    const titre = document.createElement('span');
    titre.className = 'eb-type';
    titre.textContent = def.label || donnee.type;
    entete.appendChild(titre);

    entete.appendChild(bouton('↑', 'Monter', () => deplacer(liste, index, -1)));
    entete.appendChild(bouton('↓', 'Descendre', () => deplacer(liste, index, 1)));
    entete.appendChild(bouton('✕', 'Supprimer', () => { liste.splice(index, 1); redessiner(); }));
    carte.appendChild(entete);

    const corps = document.createElement('div');
    corps.className = 'eb-corps';
    Object.entries(def.schema || {}).forEach(([cle, schema]) => {
      corps.appendChild(champ(donnee, cle, schema));
    });
    carte.appendChild(corps);

    if (def.allowChildren) {
      if (!Array.isArray(donnee.children)) donnee.children = [];
      const enfants = document.createElement('div');
      enfants.className = 'eb-enfants';
      enfants.appendChild(vueListe(donnee.children));
      carte.appendChild(enfants);
    }

    return carte;
  }

  /** Un champ de prop, selon son type (richtext, select, sinon texte). */
  function champ(donnee, cle, schema) {
    const enveloppe = document.createElement('label');
    enveloppe.className = 'eb-champ';
    const legende = document.createElement('span');
    legende.textContent = schema.label || cle;
    enveloppe.appendChild(legende);

    let entree;
    if (schema.type === 'select') {
      entree = document.createElement('select');
      (schema.options || []).forEach((option) => {
        const o = document.createElement('option');
        o.value = option;
        o.textContent = option;
        entree.appendChild(o);
      });
    } else if (schema.type === 'richtext') {
      entree = document.createElement('textarea');
      entree.rows = 4;
    } else {
      entree = document.createElement('input');
      entree.type = 'text';
    }

    entree.value = donnee.props[cle] != null ? String(donnee.props[cle]) : '';
    // Frappe : on met à jour la donnée et le textarea SANS redessiner (focus gardé).
    entree.addEventListener('input', () => { donnee.props[cle] = entree.value; ecrire(); });
    enveloppe.appendChild(entree);

    return enveloppe;
  }

  /** Barre « ajouter un bloc » sous une liste. */
  function barreAjout(liste) {
    const barre = document.createElement('div');
    barre.className = 'eb-ajout';

    const choix = document.createElement('select');
    Object.entries(catalogue).forEach(([type, def]) => {
      const o = document.createElement('option');
      o.value = type;
      o.textContent = def.label || type;
      choix.appendChild(o);
    });

    barre.appendChild(choix);
    barre.appendChild(bouton('+ Ajouter', 'Ajouter un bloc', () => {
      liste.push(nouveauBloc(choix.value));
      redessiner();
    }, 'eb-btn-ajout'));

    return barre;
  }

  function deplacer(liste, index, sens) {
    const cible = index + sens;
    if (cible < 0 || cible >= liste.length) return;
    [liste[index], liste[cible]] = [liste[cible], liste[index]];
    redessiner();
  }

  /** Crée un bloc neuf, props aux valeurs par défaut du catalogue. */
  function nouveauBloc(type) {
    const def = catalogue[type] || { schema: {}, allowChildren: false };
    const props = {};
    Object.entries(def.schema || {}).forEach(([cle, schema]) => {
      props[cle] = schema.default != null ? String(schema.default) : '';
    });

    const bloc = { id: identifiant(), type, version: 1, props };
    if (def.allowChildren) bloc.children = [];
    return bloc;
  }

  redessiner();
}

/** Parse défensif du JSON du textarea. */
function analyser(valeur) {
  if (!valeur || !valeur.trim()) return [];
  try {
    const donnee = JSON.parse(valeur);
    return Array.isArray(donnee) ? donnee : [];
  } catch {
    return [];
  }
}

function identifiant() {
  if (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function') {
    return globalThis.crypto.randomUUID();
  }
  return 'b' + Math.random().toString(36).slice(2, 10);
}

function bouton(texte, aria, action, classe) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = classe || 'eb-btn';
  b.textContent = texte;
  b.setAttribute('aria-label', aria);
  b.addEventListener('click', action);
  return b;
}
