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
<div class="admin-page">
  <div class="admin-bloc-tete">
    <h1>Commandes</h1>
    <p class="actions">
      <a class="bouton bouton--secondaire" href="<?= attr($base) ?>/admin/commandes/export.csv">Exporter en CSV</a>
    </p>
  </div>

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
            <td class="colonne-actions"><?= e(money($commande->total, Locale::Fr)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
