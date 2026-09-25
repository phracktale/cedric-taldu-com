<?php

/**
 * Liste des commandes (04-back-office, 03-boutique §7).
 *
 * Les commandes en anomalie sont signalées en tête : ce sont des
 * remboursements à traiter (03-boutique §8.5).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Repository\Admin\OrderSummary;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
/** @var list<OrderSummary> $commandes */
$commandes = is_array($data['commandes'] ?? null) ? $data['commandes'] : [];
$anomalies = is_int($data['anomalies'] ?? null) ? $data['anomalies'] : 0;
/** @var array{expediees: int, echecs: int}|null $suivi bilan de « Actualiser le suivi » */
$suivi = is_array($data['suivi'] ?? null) ? $data['suivi'] : null;
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$accord = static fn (int $n, string $singulier, string $pluriel): string => $n . ' ' . ($n > 1 ? $pluriel : $singulier);
?>
<div class="admin-page">
  <div class="admin-bloc-tete">
    <h1>Commandes</h1>
    <p class="actions">
      <a class="bouton bouton--secondaire" href="<?= attr($base) ?>/admin/commandes/export.csv">Exporter en CSV</a>
    </p>
    <form method="post" action="<?= attr($base) ?>/admin/commandes/suivi" class="actions">
      <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
      <button type="submit" class="bouton bouton--secondaire">Actualiser le suivi auprès de l’imprimeur</button>
    </form>
  </div>

  <?php if ($suivi !== null) : ?>
    <p class="aide" role="status">
      Suivi actualisé : <?= e($accord($suivi['expediees'], 'commande expédiée', 'commandes expédiées')) ?>
      <?php if ($suivi['echecs'] > 0) : ?>, <?= e($accord($suivi['echecs'], 'commande injoignable', 'commandes injoignables')) ?> chez l’imprimeur — réessayez plus tard<?php endif; ?>.
    </p>
  <?php endif; ?>

  <?php if ($anomalies > 0) : ?>
    <p class="aide aide--attention" role="alert">
      <?= e($anomalies) ?> commande(s) en <strong>anomalie</strong> — remboursement à vérifier.
    </p>
  <?php endif; ?>

  <?php if ($commandes === []) : ?>
    <p class="aide">Aucune commande pour le moment.</p>
  <?php else : ?>
    <table class="tableau">
      <thead>
        <tr>
          <th scope="col">Référence</th>
          <th scope="col">Date</th>
          <th scope="col">Client</th>
          <th scope="col">Statut</th>
          <th scope="col">Expédition</th>
          <th scope="col">Suivi</th>
          <th scope="col" class="colonne-actions">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($commandes as $commande) : ?>
          <tr<?php if ($commande->hasAnomaly) : ?> class="ligne-alerte"<?php endif; ?>>
            <td>
              <a href="<?= attr($base) ?>/admin/commandes/<?= attr($commande->id) ?>">
                <code><?= e($commande->reference) ?></code>
              </a>
              <?php if ($commande->hasAnomaly) : ?>
                <span class="pastille pastille--alerte">Anomalie</span>
              <?php endif; ?>
            </td>
            <td><?= e($commande->createdAt) ?></td>
            <td><?= e($commande->customerName) ?> — <?= e($commande->customerEmail) ?></td>
            <td><?= e($commande->status->label(Locale::Fr)) ?></td>
            <td><?= e($commande->shippingLabel()) ?></td>
            <td>
              <?php if ($commande->trackingNumber !== null && $commande->trackingUrl !== null) : ?>
                <a href="<?= attr($commande->trackingUrl) ?>" rel="noopener noreferrer" target="_blank"><?= e(trim($commande->trackingCarrier . ' ' . $commande->trackingNumber)) ?></a>
              <?php elseif ($commande->trackingNumber !== null) : ?>
                <?= e(trim($commande->trackingCarrier . ' ' . $commande->trackingNumber)) ?>
              <?php else : ?>—<?php endif; ?>
            </td>
            <td class="colonne-actions"><?= e(money($commande->total, Locale::Fr)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
