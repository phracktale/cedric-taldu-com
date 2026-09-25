/**
 * Carte interactive (retours du 2026-09-25).
 *
 * Amélioration : sans JavaScript, le bloc montre la liste des lieux. Avec, le
 * bouton « Afficher la carte » apparaît ; au clic seulement, Leaflet (servi par
 * le site) et les tuiles OpenStreetMap sont chargés — aucune requête externe
 * sans geste du visiteur (RGPD, EcoIndex). Les textes des marqueurs passent par
 * textContent : aucun HTML de données n'est injecté.
 */

const TUILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
let leaflet = null;

function charger(bloc) {
  if (leaflet) return leaflet;

  leaflet = new Promise((resoudre, rejeter) => {
    const feuille = document.createElement('link');
    feuille.rel = 'stylesheet';
    feuille.href = bloc.dataset.leafletCss;
    document.head.append(feuille);

    const script = document.createElement('script');
    script.src = bloc.dataset.leaflet;
    script.onload = () => resoudre(window.L);
    script.onerror = rejeter;
    document.head.append(script);
  });

  return leaflet;
}

async function afficher(bloc, bouton) {
  bouton.disabled = true;

  try {
    const L = await charger(bloc);
    const config = JSON.parse(bloc.dataset.config || '{}');
    const zone = bloc.querySelector('[data-carte-zone]');

    L.Icon.Default.imagePath = bloc.dataset.leafletImages;
    zone.classList.add('bloc-carte-zone--active');

    const carte = L.map(zone).setView([config.lat, config.lng], config.zoom);
    L.tileLayer(TUILES, {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(carte);

    (config.markers || []).forEach((lieu) => {
      const contenu = document.createElement('div');
      const titre = document.createElement('strong');
      titre.textContent = lieu.title;
      contenu.append(titre);
      if (lieu.description) {
        const texte = document.createElement('p');
        texte.textContent = lieu.description;
        contenu.append(texte);
      }
      L.marker([lieu.lat, lieu.lng], { title: lieu.title }).addTo(carte).bindPopup(contenu);
    });

    bouton.hidden = true;
  } catch (erreur) {
    // Leaflet injoignable : la liste des lieux reste là.
    bouton.disabled = false;
  }
}

export function initCarte() {
  document.querySelectorAll('[data-carte]').forEach((bloc) => {
    const bouton = bloc.querySelector('[data-carte-afficher]');
    if (!bouton) return;
    bouton.hidden = false;
    bouton.addEventListener('click', () => afficher(bloc, bouton));
  });
}
