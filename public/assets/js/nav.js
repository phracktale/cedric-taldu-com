/**
 * Menu principal : burger sous 900 px et sous-menu Galerie.
 *
 * Les maquettes portaient un `onclick` inline ; la CSP est stricte et sans
 * unsafe-inline, donc le comportement vit ici (06-securite §2).
 *
 * Sans JavaScript, le sous-menu reste ouvrable et parcourable : le CSS
 * `:focus-within` s'en charge, et ce fichier ne fait qu'ajouter le clic, les
 * flèches et Échap.
 */

export function initNav() {
  initBurger();
  document.querySelectorAll('.sous-menu').forEach(initSousMenu);
}

function initBurger() {
  const bouton = document.querySelector('.burger');
  const menu = bouton && document.getElementById(bouton.getAttribute('aria-controls') ?? '');

  if (!bouton || !menu) {
    return;
  }

  const ouvrir = (ouvert) => {
    menu.classList.toggle('open', ouvert);
    bouton.setAttribute('aria-expanded', String(ouvert));
  };

  bouton.addEventListener('click', () => ouvrir(bouton.getAttribute('aria-expanded') !== 'true'));

  // Menu mobile (demande du 2026-10-13) : Échap et un clic ailleurs le referment.
  document.addEventListener('keydown', (evenement) => {
    if (evenement.key === 'Escape' && bouton.getAttribute('aria-expanded') === 'true') {
      ouvrir(false);
      bouton.focus();
    }
  });
  document.addEventListener('click', (evenement) => {
    if (!menu.contains(evenement.target) && !bouton.contains(evenement.target)) {
      ouvrir(false);
    }
  });
}

function initSousMenu(conteneur) {
  const bouton = conteneur.querySelector('button');
  const liste = conteneur.querySelector('ul');

  if (!bouton || !liste) {
    return;
  }

  // Le sous-menu est fermé une fois le JavaScript en place : jusque-là, le CSS
  // le laissait accessible au clavier. On ne retire donc rien avant d'avoir de
  // quoi le remplacer.
  const ouvrir = (ouvert) => {
    conteneur.toggleAttribute('open', ouvert);
    bouton.setAttribute('aria-expanded', String(ouvert));
  };

  ouvrir(false);

  bouton.addEventListener('click', () => {
    ouvrir(bouton.getAttribute('aria-expanded') !== 'true');
  });

  // Ouverture au survol, mais uniquement sur pointeur fin : sur un écran
  // tactile, « survol » signifie « premier appui » et volerait le clic.
  //
  // Et seulement pour un pointeur VENU DE L'EXTÉRIEUR. Juste après un clic sur
  // « Galerie », la nouvelle page se charge sous un pointeur resté sur
  // l'entrée : son premier mouvement déclenchait mouseenter, et le sous-menu
  // s'ouvrait à chaque navigation (retour du 2026-10-29). Le survol n'est donc
  // armé qu'après un mouvement hors du menu.
  if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    let survolArme = false;

    document.addEventListener('pointermove', (evenement) => {
      if (!conteneur.contains(evenement.target)) {
        survolArme = true;
      }
    }, { passive: true });

    conteneur.addEventListener('mouseenter', () => {
      if (survolArme) {
        ouvrir(true);
      }
    });
    conteneur.addEventListener('mouseleave', () => ouvrir(false));
  }

  conteneur.addEventListener('keydown', (evenement) => {
    const liens = [...liste.querySelectorAll('a')];

    if (evenement.key === 'Escape') {
      ouvrir(false);
      bouton.focus();
      return;
    }

    if (evenement.key === 'ArrowDown') {
      evenement.preventDefault();
      ouvrir(true);
      const index = liens.indexOf(document.activeElement);
      (liens[index + 1] ?? liens[0])?.focus();
      return;
    }

    if (evenement.key === 'ArrowUp') {
      evenement.preventDefault();
      const index = liens.indexOf(document.activeElement);
      (index <= 0 ? bouton : liens[index - 1])?.focus();
    }
  });

  // Fermeture au clic extérieur.
  document.addEventListener('click', (evenement) => {
    if (!conteneur.contains(evenement.target)) {
      ouvrir(false);
    }
  });
}
