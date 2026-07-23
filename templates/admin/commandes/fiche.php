<?php

/**
 * Fiche d'une commande (04-back-office, 03-boutique §7).
 *
 * L'artiste voit le détail, l'adresse, les éventuelles anomalies, et peut
 * expédier une commande payée. Le passage à « payé » n'est jamais offert ici :
 * il n'appartient qu'au webhook signé.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Order\OrderStatus;
use App\Repository\PersistedOrder;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var PersistedOrder $order */
$order = $data['order'];
?>
<section class="admin-commande">
  <p><a href="<?= attr($base) ?>/admin/commandes">← Toutes les commandes</a></p>

  <h1>Commande <?= e($order->reference) ?></h1>
  <p class="admin-statut">Statut : <strong><?= e($order->status->label(Locale::Fr)) ?></strong></p>

  <?php if ($order->hasAnomaly()) : ?>
    <p class="admin-anomalie" role="alert">
      <strong>Anomalie :</strong> <?= e((string) $order->anomalyNote) ?>
    </p>
  <?php endif; ?>

  <h2>Client</h2>
  <p>
    <?= e($order->customerName) ?><br>
    <?= e($order->customerEmail) ?>
    <?php if ($order->customerPhone !== null) : ?><br><?= e($order->customerPhone) ?><?php endif; ?>
  </p>

  <?php if ($order->shippingAddress !== null) : ?>
    <h2>Adresse de livraison</h2>
    <p>
      <?= e($order->shippingAddress->line1) ?><br>
      <?php if ($order->shippingAddress->line2 !== null) : ?><?= e($order->shippingAddress->line2) ?><br><?php endif; ?>
      <?= e($order->shippingAddress->postalCode) ?> <?= e($order->shippingAddress->city) ?><br>
      <?= e($order->shippingAddress->country) ?>
    </p>
  <?php else : ?>
    <p>Remise en main propre à Amiens.</p>
  <?php endif; ?>

  <h2>Articles</h2>
  <table class="admin-table">
    <thead><tr><th>Article</th><th>SKU</th><th>Qté</th><th>N° édition</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach ($order->lines as $line) : ?>
        <tr<?php if ($line->anomaly !== null) : ?> class="ligne-anomalie"<?php endif; ?>>
          <td><?= e($line->label) ?></td>
          <td><?= e($line->sku ?? '—') ?></td>
          <td><?= e($line->quantity) ?></td>
          <td><?= e($line->editionNumber !== null ? (string) $line->editionNumber : '—') ?></td>
          <td><?= e(money($line->total, Locale::Fr)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="4">Sous-total</th><td><?= e(money($order->subtotal, Locale::Fr)) ?></td></tr>
      <tr><th colspan="4">Frais de port</th><td><?= e(money($order->shipping, Locale::Fr)) ?></td></tr>
      <tr><th colspan="4">Total</th><td><strong><?= e(money($order->total, Locale::Fr)) ?></strong></td></tr>
    </tfoot>
  </table>

  <?php if ($order->legalMention() !== null) : ?>
    <p class="admin-mention"><?= e($order->legalMention()) ?></p>
  <?php endif; ?>

  <?php if ($order->status === OrderStatus::Paid) : ?>
    <h2>Expédier</h2>
    <form method="post" action="<?= attr($base) ?>/admin/commandes/<?= attr($order->id) ?>/expedition">
      <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
      <label for="transporteur">Transporteur</label>
      <input type="text" id="transporteur" name="transporteur" required maxlength="60">
      <label for="suivi">Numéro de suivi</label>
      <input type="text" id="suivi" name="suivi" required maxlength="80">
      <button type="submit" class="btn btn-plein">Marquer comme expédiée</button>
    </form>
  <?php elseif ($order->status === OrderStatus::Shipped) : ?>
    <h2>Expédition</h2>
    <p>
      Expédiée par <?= e((string) $order->trackingCarrier) ?>,
      suivi <?= e((string) $order->trackingNumber) ?>.
    </p>
  <?php endif; ?>
</section>
