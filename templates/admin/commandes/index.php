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
?>
<section class="admin-commandes">
  <div class="admin-entete">
    <h1>Commandes</h1>
    <a class="btn" href="<?= attr($base) ?>/admin/commandes/export.csv">Exporter en CSV</a>
  </div>

  <?php if ($anomalies > 0) : ?>
    <p class="admin-anomalie" role="alert">
      <?= e($anomalies) ?> commande(s) en <strong>anomalie</strong> — remboursement à vérifier.
    </p>
  <?php endif; ?>

  <?php if ($commandes === []) : ?>
    <p>Aucune commande pour le moment.</p>
  <?php else : ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>Référence</th><th>Date</th><th>Client</th><th>Statut</th><th>Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($commandes as $commande) : ?>
          <tr<?php if ($commande->hasAnomaly) : ?> class="ligne-anomalie"<?php endif; ?>>
            <td>
              <a href="<?= attr($base) ?>/admin/commandes/<?= attr($commande->id) ?>">
                <?= e($commande->reference) ?>
              </a>
              <?php if ($commande->hasAnomaly) : ?>
                <span class="badge-anomalie" title="Anomalie">⚠ anomalie</span>
              <?php endif; ?>
            </td>
            <td><?= e($commande->createdAt) ?></td>
            <td><?= e($commande->customerName) ?> — <?= e($commande->customerEmail) ?></td>
            <td><?= e($commande->status->label(Locale::Fr)) ?></td>
            <td><?= e(money($commande->total, Locale::Fr)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
