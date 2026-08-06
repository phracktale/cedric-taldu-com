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
/** @var array{prodigiOrderId: string|null, status: string|null, submittedAt: string|null}|null $prodigi */
$prodigi = $data['prodigi'] ?? null;
?>
<div class="admin-page">
  <p class="actions">
    <a class="bouton bouton--secondaire" href="<?= attr($base) ?>/admin/commandes">← Toutes les commandes</a>
  </p>

  <div class="admin-bloc-tete">
    <h1>Commande <?= e($order->reference) ?></h1>
    <p>Statut : <strong><?= e($order->status->label(Locale::Fr)) ?></strong></p>
  </div>

  <?php if ($order->hasAnomaly()) : ?>
    <p class="aide aide--attention" role="alert">
      <strong>Anomalie :</strong> <?= e((string) $order->anomalyNote) ?>
    </p>
  <?php endif; ?>

  <section class="admin-bloc">
    <h2>Client &amp; livraison</h2>
    <p>
      <?= e($order->customerName) ?><br>
      <?= e($order->customerEmail) ?>
      <?php if ($order->customerPhone !== null) : ?><br><?= e($order->customerPhone) ?><?php endif; ?>
    </p>

    <?php if ($order->shippingAddress !== null) : ?>
      <p>
        <?= e($order->shippingAddress->line1) ?><br>
        <?php if ($order->shippingAddress->line2 !== null) : ?><?= e($order->shippingAddress->line2) ?><br><?php endif; ?>
        <?= e($order->shippingAddress->postalCode) ?> <?= e($order->shippingAddress->city) ?><br>
        <?= e($order->shippingAddress->country) ?>
      </p>
    <?php else : ?>
      <p class="aide">Remise en main propre à Amiens.</p>
    <?php endif; ?>
  </section>

  <section class="admin-bloc">
    <h2>Articles</h2>
    <table class="tableau">
      <thead>
        <tr>
          <th scope="col">Article</th>
          <th scope="col">SKU</th>
          <th scope="col">Qté</th>
          <th scope="col">N° édition</th>
          <th scope="col" class="colonne-actions">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($order->lines as $line) : ?>
          <tr<?php if ($line->anomaly !== null) : ?> class="ligne-alerte"<?php endif; ?>>
            <td><?= e($line->label) ?></td>
            <td><code><?= e($line->sku ?? '—') ?></code></td>
            <td><?= e($line->quantity) ?></td>
            <td><?= e($line->editionNumber !== null ? (string) $line->editionNumber : '—') ?></td>
            <td class="colonne-actions"><?= e(money($line->total, Locale::Fr)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="4">Sous-total</th><td class="colonne-actions"><?= e(money($order->subtotal, Locale::Fr)) ?></td></tr>
        <tr><th colspan="4">Frais de port</th><td class="colonne-actions"><?= e(money($order->shipping, Locale::Fr)) ?></td></tr>
        <tr><th colspan="4">Total</th><td class="colonne-actions"><strong><?= e(money($order->total, Locale::Fr)) ?></strong></td></tr>
      </tfoot>
    </table>

    <?php if ($order->legalMention() !== null) : ?>
      <p class="aide"><?= e($order->legalMention()) ?></p>
    <?php endif; ?>
  </section>

  <?php if ($prodigi !== null) : ?>
    <section class="admin-bloc">
      <h2>Impression Prodigi</h2>
      <?php if ($prodigi['prodigiOrderId'] !== null) : ?>
        <p>
          Commande Prodigi <strong><?= e($prodigi['prodigiOrderId']) ?></strong>,
          statut <strong><?= e($prodigi['status'] ?? '—') ?></strong>
          <?php if ($prodigi['submittedAt'] !== null) : ?>
            (soumise le <?= e($prodigi['submittedAt']) ?>)
          <?php endif; ?>.
        </p>
        <p class="aide">
          Le suivi et le passage en « expédiée » sont mis à jour automatiquement par
          les callbacks Prodigi.
        </p>
      <?php else : ?>
        <p class="aide">Cette commande n’a pas encore été soumise à Prodigi.</p>
        <form method="post" action="<?= attr($base) ?>/admin/commandes/<?= attr($order->id) ?>/prodigi">
          <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
          <p class="actions"><button type="submit" class="bouton">Soumettre à Prodigi</button></p>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($order->status === OrderStatus::Paid) : ?>
    <section class="admin-bloc">
      <h2>Expédier</h2>
      <form method="post" action="<?= attr($base) ?>/admin/commandes/<?= attr($order->id) ?>/expedition"
            class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
        <div class="grille-champs">
          <p class="champ">
            <label for="transporteur">Transporteur</label>
            <input type="text" id="transporteur" name="transporteur" required maxlength="60">
          </p>
          <p class="champ">
            <label for="suivi">Numéro de suivi</label>
            <input type="text" id="suivi" name="suivi" required maxlength="80">
          </p>
        </div>
        <p class="actions"><button type="submit" class="bouton">Marquer comme expédiée</button></p>
      </form>
    </section>
  <?php elseif ($order->status === OrderStatus::Shipped) : ?>
    <section class="admin-bloc">
      <h2>Expédition</h2>
      <p>
        Expédiée par <?= e((string) $order->trackingCarrier) ?>,
        suivi <?= e((string) $order->trackingNumber) ?>.
      </p>
    </section>
  <?php endif; ?>
</div>
