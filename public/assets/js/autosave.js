/**
 * Enregistrement automatique des formulaires de saisie (demande du 2026-10-29).
 *
 * Chaque `form[data-autosave]` s'enregistre de lui-même :
 *   - une case, un bouton radio, une liste, un fichier : dès qu'ils changent ;
 *   - un champ de texte : quand on le quitte — un enregistrement par champ, pas
 *     par lettre, car l'historique garde chaque version ;
 *   - un éditeur de blocs ou un compositeur : quand sa structure change.
 * Les boutons « Enregistrer » sont alors masqués ; un témoin, en bas à droite,
 * dit « Enregistrement… », « Enregistré à 14:32 » ou ce qui a été refusé.
 *
 * Rien ne change côté serveur : le formulaire entier part, avec son jeton, vers
 * la même adresse qu'un envoi classique, et sa validation s'applique. Sans
 * JavaScript, les boutons restent et tout marche comme avant (04-back-office §12).
 *
 *   data-autosave             mise à jour en place
 *   data-autosave="creation"  le premier enregistrement crée l'élément : la
 *                             page bascule ensuite sur sa fiche d'édition
 *   data-autosave="recharger" la page se recharge après l'enregistrement
 */

const HEURE = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' });
const RELANCE_MS = 10000;

export function monterEnregistrementAutomatique(racine = document) {
  const formulaires = [...racine.querySelectorAll('form[data-autosave]')];

  if (formulaires.length === 0) {
    return;
  }

  const temoin = creerTemoin();
  const suivis = formulaires.map((formulaire) => armer(formulaire, temoin));

  // Après une création ou un rechargement : la page précédente l'a annoncé.
  const annonce = reprendre();
  if (annonce !== null) {
    afficher(temoin, annonce, 'ok');
  }

  // Une saisie pas encore partie, ou refusée : quitter la page demande confirmation.
  window.addEventListener('beforeunload', (evenement) => {
    if (!suivis.some((suivi) => suivi.nonEnregistre())) {
      return;
    }
    evenement.preventDefault();
    evenement.returnValue = '';
  });
}

/** Message transmis à la page suivante, quand l'enregistrement fait changer de page. */
function retenir(message) {
  try {
    window.sessionStorage.setItem('autosave-annonce', message);
  } catch {
    // Stockage indisponible : la page suivante n'annoncera rien, sans gravité.
  }
}

function reprendre() {
  try {
    const message = window.sessionStorage.getItem('autosave-annonce');
    window.sessionStorage.removeItem('autosave-annonce');
    return message;
  } catch {
    return null;
  }
}

function creerTemoin() {
  const temoin = document.createElement('p');
  temoin.className = 'autosave-temoin';
  temoin.setAttribute('role', 'status');
  temoin.hidden = true;
  document.body.appendChild(temoin);

  return temoin;
}

function afficher(temoin, texte, etat) {
  temoin.textContent = texte;
  temoin.dataset.etat = etat;
  temoin.hidden = false;
}

function armer(formulaire, temoin) {
  // Les boutons « Enregistrer » ne servent plus. Un bouton nommé porte une
  // action particulière : il reste.
  formulaire
    .querySelectorAll('button[type="submit"]:not([name]), input[type="submit"]:not([name])')
    .forEach((bouton) => { bouton.hidden = true; });

  let enCours = false;
  let aRefaire = false;
  let enAttente = false;
  let enErreur = false;
  let relance = null;

  const enregistrer = async () => {
    if (enCours) {
      aRefaire = true;
      return;
    }

    // Une création attend ses champs obligatoires : inutile de la proposer au
    // serveur, qui la refuserait, avant qu'ils soient remplis.
    if (formulaire.dataset.autosave === 'creation' && !formulaire.checkValidity()) {
      // Le premier CHAMP en défaut : un fieldset qui en contient un est aussi « :invalid ».
      const manquant = formulaire.querySelector('input:invalid, select:invalid, textarea:invalid');
      const libelle = manquant?.labels?.[0]?.textContent?.trim() ?? '';
      enAttente = true;
      afficher(temoin, `Pas encore enregistré : ${libelle !== '' ? `« ${libelle} » est à remplir` : 'complétez les champs obligatoires'}.`, 'attente');
      return;
    }

    enCours = true;
    aRefaire = false;
    enAttente = false;
    clearTimeout(relance);
    afficher(temoin, 'Enregistrement…', 'en-cours');

    const avecFichier = [...formulaire.querySelectorAll('input[type="file"]')]
      .some((champ) => (champ.files?.length ?? 0) > 0);
    let quitter = null;

    try {
      const reponse = await fetch(new URL(formulaire.getAttribute('action') ?? '', window.location.href), {
        method: 'POST',
        body: new FormData(formulaire),
        credentials: 'same-origin',
      });

      // Session close : le serveur renvoie vers la connexion, rien n'est enregistré.
      if (new URL(reponse.url).pathname.endsWith('/admin/connexion')) {
        enErreur = true;
        afficher(temoin, 'Non enregistré : la session est close. Reconnectez-vous dans un autre onglet, puis modifiez un champ.', 'erreur');
        return;
      }

      if (!reponse.ok) {
        enErreur = true;
        await signalerErreurs(formulaire, temoin, reponse);
        return;
      }

      enErreur = false;
      effacerErreurs(formulaire);

      if (formulaire.dataset.autosave === 'creation') {
        // L'élément existe : la suite le met à jour, puis sa fiche s'affiche.
        formulaire.setAttribute('action', reponse.url);
        formulaire.dataset.autosave = 'recharger';
      }

      if (formulaire.dataset.autosave === 'recharger' || avecFichier) {
        quitter = reponse.url;
        retenir(`Enregistré à ${HEURE.format(new Date())}`);
      }

      afficher(temoin, `Enregistré à ${HEURE.format(new Date())}`, 'ok');
    } catch {
      enAttente = true;

      // Une création a pu aboutir avant la coupure : la relancer en ferait un
      // doublon. Une mise à jour renvoie l'état complet, la relancer est sûr.
      if (formulaire.dataset.autosave === 'creation') {
        enErreur = true;
        afficher(temoin, 'Non enregistré : connexion perdue. Vérifiez dans la liste si l’élément a été créé avant de continuer.', 'erreur');
      } else {
        afficher(temoin, 'Non enregistré : connexion perdue. Nouvel essai dans quelques secondes…', 'erreur');
        relance = setTimeout(enregistrer, RELANCE_MS);
      }
    } finally {
      enCours = false;

      if (aRefaire) {
        enregistrer();
      } else if (quitter !== null) {
        window.location.assign(quitter);
      }
    }
  };

  formulaire.addEventListener('input', () => {
    enAttente = true;
  });

  formulaire.addEventListener('change', (evenement) => {
    const champ = evenement.target;

    // Un sélecteur de fichier vidé n'a rien à envoyer.
    if (champ instanceof HTMLInputElement && champ.type === 'file' && (champ.files?.length ?? 0) === 0) {
      return;
    }

    enregistrer();
  });

  // Entrée dans un champ : on enregistre en place, sans quitter la page.
  formulaire.addEventListener('submit', (evenement) => {
    evenement.preventDefault();
    enregistrer();
  });

  return { nonEnregistre: () => enCours || enAttente || enErreur };
}

/**
 * Validation refusée : la page renvoyée (422) porte les erreurs du formulaire.
 * On en reprend les champs fautifs et leurs messages, sous les mêmes champs.
 */
async function signalerErreurs(formulaire, temoin, reponse) {
  effacerErreurs(formulaire);

  if (reponse.status === 403) {
    afficher(temoin, 'Non enregistré : la page a expiré. Rechargez-la pour continuer.', 'erreur');
    return;
  }

  const page = new DOMParser().parseFromString(await reponse.text(), 'text/html');
  const messages = [];

  page.querySelectorAll('[aria-invalid="true"][name]').forEach((fautif) => {
    const message = fautif.parentElement?.querySelector('.champ-erreur')?.textContent?.trim() ?? '';
    const ici = formulaire.querySelector(`[name="${CSS.escape(fautif.getAttribute('name') ?? '')}"]`);

    if (message !== '') {
      messages.push(message);
    }

    if (ici === null) {
      return;
    }

    ici.setAttribute('aria-invalid', 'true');

    if (message !== '') {
      const note = document.createElement('span');
      note.className = 'champ-erreur';
      note.setAttribute('role', 'alert');
      note.dataset.autosaveErreur = '';
      note.textContent = message;
      ici.insertAdjacentElement('afterend', note);
    }
  });

  if (messages.length === 0) {
    page.querySelectorAll('.erreur, p[role="alert"]').forEach((alerte) => {
      const texte = alerte.textContent?.trim() ?? '';
      if (texte !== '') {
        messages.push(texte);
      }
    });
  }

  // Une création encore incomplète n'est pas une erreur : elle attend la suite.
  const creation = formulaire.dataset.autosave === 'creation';
  afficher(
    temoin,
    `${creation ? 'Pas encore enregistré' : 'Non enregistré'} : ${messages[0] ?? `erreur ${reponse.status}`}`,
    creation ? 'attente' : 'erreur',
  );
}

function effacerErreurs(formulaire) {
  formulaire.querySelectorAll('[data-autosave-erreur]').forEach((note) => note.remove());
  formulaire.querySelectorAll('[aria-invalid="true"]').forEach((champ) => champ.removeAttribute('aria-invalid'));
}
