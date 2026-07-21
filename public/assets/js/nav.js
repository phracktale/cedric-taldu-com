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

  bouton.addEventListener('click', () => {
    const ouvert = menu.classList.toggle('open');
    bouton.setAttribute('aria-expanded', String(ouvert));
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
  if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    conteneur.addEventListener('mouseenter', () => ouvrir(true));
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
