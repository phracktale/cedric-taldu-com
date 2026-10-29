<?php

/**
 * « Annuler et rembourser » une commande (demande du 2026-10-29).
 *
 * L'opération est définitive : elle passe par Stripe, puis remet le stock en
 * place. D'où cette page de confirmation, avec une case à cocher expresse.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Order\OrderStatus;
use App\Repository\PersistedOrder;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var PersistedOrder $order */
$order = $data['order'];
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
$fiche = $base . '/admin/commandes/' . $order->id;
?>
<div class="admin-page admin-page--etroite">
  <p class="actions">
    <a class="bouton bouton--secondaire" href="<?= attr($fiche) ?>">← Retour à la commande</a>
  </p>

  <h1>Annuler et rembourser la commande <?= e($order->reference) ?></h1>

  <?php if ($erreur !== null) : ?>
  <p class="aide aide--attention" role="alert"><?= e($erreur) ?></p>
  <?php endif; ?>

  <?php if (!$order->status->isRefundable()) : ?>
  <p class="aide">Cette commande (<?= e($order->status->label(Locale::Fr)) ?>) n’a rien à rembourser.</p>
  <?php elseif ($order->paymentReference === null) : ?>
  <p class="aide">
    Aucun paiement Stripe n’est rattaché à cette commande : remboursez-la depuis le tableau de bord Stripe.
  </p>
  <?php else : ?>
  <p class="aide">
    <strong><?= e(money($order->total, Locale::Fr)) ?></strong> seront rendus à <?= e($order->customerName) ?>
    (<?= e($order->customerEmail) ?>) par Stripe, sur le moyen de paiement utilisé — comptez quelques
    jours ouvrés. La commande passera à « Remboursée » et son stock sera remis en place : pièce originale
    remise en vente, exemplaires rajoutés au stock, stock illimité inchangé, tirages rendus à leur format.
  </p>
  <?php if ($order->status === OrderStatus::Shipped) : ?>
  <p class="aide aide--attention">
    <strong>Commande déjà expédiée :</strong> attendez le retour de l’œuvre avant de rembourser, puisqu’elle
    sera remise en vente.
  </p>
  <?php endif; ?>

  <form method="post" action="<?= attr($fiche . '/remboursement') ?>" class="formulaire">
    <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
    <p class="champ champ-inline">
      <input type="checkbox" id="confirmation" name="confirmation" value="1" required>
      <label for="confirmation">Je confirme l’annulation de la commande et le remboursement intégral de
        <?= e(money($order->total, Locale::Fr)) ?>. Cette opération est définitive.</label>
    </p>
    <p class="actions">
      <button type="submit" class="bouton bouton--danger">Annuler et rembourser</button>
    </p>
  </form>
  <?php endif; ?>
</div>
