/**
 * Mesure d'audience par Matomo auto-hébergé (revue du 2026-09-24).
 *
 * Configuration exemptée de consentement (CNIL) : aucun cookie
 * (disableCookies), pas de suivi inter-sites ; l'anonymisation de l'adresse IP
 * se règle côté serveur Matomo. L'adresse et le numéro du site viennent des
 * attributs data du <script> qui charge ce module — aucun script en ligne,
 * CSP stricte respectée : seule l'origine Matomo configurée y est autorisée.
 */

const moi = document.querySelector('script[data-matomo-url][data-matomo-site]');

if (moi) {
  const base = moi.dataset.matomoUrl;
  const site = moi.dataset.matomoSite;
  const paq = (window._paq = window._paq || []);

  paq.push(['disableCookies']);
  paq.push(['setTrackerUrl', base + 'matomo.php']);
  paq.push(['setSiteId', site]);
  paq.push(['trackPageView']);
  paq.push(['enableLinkTracking']);

  const traceur = document.createElement('script');
  traceur.async = true;
  traceur.src = base + 'matomo.js';
  document.head.appendChild(traceur);
}
