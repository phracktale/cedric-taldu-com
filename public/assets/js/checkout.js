/**
 * Tunnel de commande : reflète le mode de remise choisi (03-boutique §3).
 *
 * AMÉLIORATION pure (§12) : sans JavaScript, l'écran affiche le mode par défaut
 * (expédition) et le serveur recalcule tout au paiement. Ce module met à jour le
 * récapitulatif — frais de port, total, bouton — et la fenêtre de réception dès
 * qu'on bascule entre expédition et retrait, sans aucun calcul monétaire côté
 * client : il ne fait que réafficher des libellés déjà formatés par le serveur.
 */

export function initCheckout() {
  const form = document.querySelector('form[data-commande]');
  if (!form) return;

  const port = form.querySelector('[data-recap-port]');
  const total = form.querySelector('[data-recap-total]');
  const bouton = form.querySelector('[data-recap-bouton]');
  const adresse = form.querySelector('[data-commande-adresse]');
  const quandExpedition = form.querySelector('[data-quand-expedition]');
  const quandRetrait = form.querySelector('[data-quand-retrait]');
  const modes = form.querySelectorAll('input[name="mode"]');

  const refleter = (choisi) => {
    if (port) port.textContent = choisi.dataset.prix || '';
    if (total) total.textContent = choisi.dataset.total || '';
    if (bouton) bouton.textContent = choisi.dataset.total ? ` — ${choisi.dataset.total}` : '';

    const retrait = choisi.dataset.mode === 'pickup';
    if (adresse) adresse.hidden = retrait;
    if (quandExpedition) quandExpedition.hidden = retrait;
    if (quandRetrait) quandRetrait.hidden = !retrait;
  };

  modes.forEach((mode) => {
    mode.addEventListener('change', () => refleter(mode));
    if (mode.checked) refleter(mode);
  });
}
