<?php

/**
 * Panier (03-boutique §2 et §3, étape 1).
 *
 * Récapitulatif éditable. Chaque montant vient de la valorisation calculée
 * côté serveur ; aucun prix n'est écrit dans un champ que le client renvoie.
 * Les messages de correction (œuvre acquise, stock réduit) sont affichés en
 * tête, faute de quoi un total qui change sans explication passe pour une
 * erreur du site.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Shop\CartValuation;
use App\Domain\Shop\LineKind;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var CartValuation $valuation */
$valuation = $data['valuation'];
/** @var string $panierUrl */
$panierUrl = $data['panierUrl'];
/** @var string $checkoutUrl */
$checkoutUrl = $data['checkoutUrl'];
/** @var string $nonce */
$nonce = $data['nonce'];
/** @var string $csrfToken */
$csrfToken = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

$updateUrl = $url->route('cart.update', ['locale' => $locale->value]);
$removeUrl = $url->route('cart.remove', ['locale' => $locale->value]);
?>
<main class="panier" id="contenu">
  <div class="wrap">
    <h1><?= $t('cart.title') ?></h1>

    <?php foreach ($valuation->notices as $notice) : ?>
      <p class="panier-message" role="status"><?= e($notice->message($locale)) ?></p>
    <?php endforeach; ?>

    <?php if ($valuation->isEmpty()) : ?>
      <p class="panier-vide"><?= $t('cart.empty') ?></p>
    <?php else : ?>
      <table class="panier-lignes">
        <thead>
          <tr>
            <th><?= $t('cart.item') ?></th>
            <th><?= $t('cart.quantity') ?></th>
            <th><?= $t('cart.amount') ?></th>
            <th><span class="visually-hidden"><?= $t('cart.remove') ?></span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($valuation->lines as $line) : ?>
            <?php
            $item = $line->item;
            $isReproduction = $item->kind === LineKind::Reproduction;
            ?>
            <tr>
              <td class="panier-article"><?= e($item->label) ?></td>
              <td class="panier-quantite">
                <?php if ($isReproduction) : ?>
                  <form method="post" action="<?= attr($updateUrl) ?>" class="panier-form">
                    <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">
                    <input type="hidden" name="kind" value="<?= attr($item->kind->value) ?>">
                    <input type="hidden" name="id" value="<?= attr($item->targetId) ?>">
                    <label class="visually-hidden" for="qte-<?= attr($item->targetId) ?>">
                      <?= $t('cart.quantity') ?>
                    </label>
                    <input type="number" id="qte-<?= attr($item->targetId) ?>" name="quantite"
                           min="0" max="5" value="<?= attr($line->quantity) ?>" inputmode="numeric">
                    <button type="submit"><?= $t('cart.update') ?></button>
                  </form>
                <?php else : ?>
                  <?= e($line->quantity) ?>
                <?php endif; ?>
              </td>
              <td class="panier-montant"><?= e(money($line->total, $locale)) ?></td>
              <td class="panier-retrait">
                <form method="post" action="<?= attr($removeUrl) ?>">
                  <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">
                  <input type="hidden" name="kind" value="<?= attr($item->kind->value) ?>">
                  <input type="hidden" name="id" value="<?= attr($item->targetId) ?>">
                  <button type="submit"><?= $t('cart.remove') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="2"><?= $t('cart.subtotal') ?></th>
            <td colspan="2"><?= e(money($valuation->subtotal, $locale)) ?></td>
          </tr>
        </tfoot>
      </table>

      <p class="panier-rappel"><?= $t('cart.reminder') ?></p>

      <div class="panier-actions">
        <a class="btn btn-plein" href="<?= attr($checkoutUrl) ?>"><?= $t('cart.checkout') ?></a>
      </div>
    <?php endif; ?>
  </div>
</main>
