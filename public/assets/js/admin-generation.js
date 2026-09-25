/**
 * Barre de génération statique (retours du 2026-09-25, point 7).
 *
 * Amélioration : sans JavaScript, « Régénérer » poste le formulaire et revient
 * au tableau de bord. Ici, la génération part en arrière-plan, la barre et le
 * journal se mettent à jour sur place — et un site périmé (après un
 * enregistrement) est régénéré d'office, dès l'arrivée sur la page suivante.
 */

const pad = (n, t = 2) => String(n).padStart(t, '0');

function heure(iso) {
  const d = new Date(iso);
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}.${pad(d.getMilliseconds(), 3)}`;
}

function secondes(ms) {
  return (ms / 1000).toFixed(1).replace('.', ',');
}

function rendre(barre, etat) {
  const ligne = barre.querySelector('.barre-ligne');
  const formulaire = barre.querySelector('[data-generation-form]');
  ligne.querySelectorAll('span').forEach((s) => s.remove());

  const quand = new Date(etat.at);
  const morceaux = [
    ['barre-pages', `${etat.count} pages statiques`],
    ['barre-numero', `Génération n° ${etat.number}`],
    ['barre-date', `le ${quand.toLocaleDateString('fr-FR')} à ${pad(quand.getHours())}:${pad(quand.getMinutes())}`],
    ['barre-etat', 'À jour'],
  ];
  morceaux.forEach(([classe, texte]) => {
    const span = document.createElement('span');
    span.className = classe;
    span.textContent = texte;
    ligne.insertBefore(span, formulaire);
  });

  let bloc = barre.querySelector('.barre-journal-bloc');
  if (!bloc) {
    bloc = document.createElement('details');
    bloc.className = 'barre-journal-bloc';
    bloc.innerHTML = '<summary>Journal</summary><pre class="barre-journal" role="log"></pre>';
    barre.append(bloc);
  }

  const lignes = etat.log.map((l) => `[${heure(l.at)}] ${l.path.padEnd(60)} ${String(l.ms).padStart(5)} ms`
    + (l.status === 200 ? '' : `  (${l.status}, non écrite)`));
  lignes.push(`Total : ${etat.count} pages en ${secondes(etat.total_ms)} s`);
  bloc.querySelector('pre').textContent = lignes.join('\n');
  barre.removeAttribute('data-stale');
}

async function generer(barre, formulaire) {
  const bouton = formulaire.querySelector('button');
  bouton.disabled = true;
  bouton.textContent = 'Génération…';

  try {
    const reponse = await fetch(formulaire.action, {
      method: 'POST',
      body: new FormData(formulaire),
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (reponse.ok) {
      rendre(barre, await reponse.json());
    }
  } catch (erreur) {
    // Échec réseau : la barre garde l'état périmé, le bouton reste utilisable.
  } finally {
    bouton.disabled = false;
    bouton.textContent = 'Régénérer';
  }
}

export function monterBarreGeneration() {
  const barre = document.querySelector('[data-generation]');
  const formulaire = barre?.querySelector('[data-generation-form]');
  if (!barre || !formulaire || typeof window.fetch !== 'function') {
    return;
  }

  formulaire.addEventListener('submit', (evenement) => {
    evenement.preventDefault();
    generer(barre, formulaire);
  });

  if (barre.hasAttribute('data-stale')) {
    generer(barre, formulaire);
  }
}
