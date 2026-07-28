<?php

/**
 * Formulaire de commande (03-boutique §3, étape 2).
 *
 * Un seul écran : identité, mode de remise, adresse, note, acceptation des CGV.
 * AUCUN champ de prix : les montants sont recalculés côté serveur. Le champ
 * appât `site_web` est masqué hors écran, jamais en display:none seul, et porte
 * aria-hidden + tabindex=-1 (06-securite §6.1).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Shop\CartValuation;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var CartValuation $valuation */
$valuation = $data['valuation'];
/** @var string $submitUrl */
$submitUrl = $data['submitUrl'];
/** @var string $honeypot */
$honeypot = $data['honeypot'];
/** @var string $csrfToken */
$csrfToken = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var string|null $error */
$error = is_string($data['error'] ?? null) ? $data['error'] : null;

$cgvUrl = $url->route('page.terms', ['locale' => $locale->value]);
// Version PDF des CGV, par langue (servie sous public/assets/documents/).
$cgvPdfUrl = $url->asset('documents/cgv-cedric-taldu-' . $locale->value . '.pdf');
?>
<main class="commande" id="contenu">
  <div class="wrap">
    <h1><?= $t('checkout.title') ?></h1>

    <?php if ($error !== null) : ?>
      <p class="commande-erreur" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <section class="commande-recap">
      <h2><?= $t('checkout.summary') ?></h2>
      <ul>
        <?php foreach ($valuation->lines as $line) : ?>
          <li>
            <span><?= e($line->item->label) ?> × <?= e($line->quantity) ?></span>
            <span><?= e(money($line->total, $locale)) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="commande-soustotal">
        <strong><?= $t('cart.subtotal') ?></strong>
        <?= e(money($valuation->subtotal, $locale)) ?>
      </p>
    </section>

    <form method="post" action="<?= attr($submitUrl) ?>" class="commande-form">
      <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">

      <?php // Champ appât : positionné hors écran, jamais atteignable au clavier. ?>
      <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;" tabindex="-1">
        <label for="<?= attr($honeypot) ?>"><?= $t('form.do_not_fill') ?></label>
        <input type="text" id="<?= attr($honeypot) ?>" name="<?= attr($honeypot) ?>"
               tabindex="-1" autocomplete="off">
      </div>

      <fieldset>
        <legend><?= $t('checkout.your_details') ?></legend>
        <label for="nom"><?= $t('checkout.name') ?></label>
        <input type="text" id="nom" name="nom" required maxlength="160">

        <label for="email"><?= $t('checkout.email') ?></label>
        <input type="email" id="email" name="email" required maxlength="190">

        <label for="telephone"><?= $t('checkout.phone') ?></label>
        <input type="tel" id="telephone" name="telephone" maxlength="40">
      </fieldset>

      <fieldset>
        <legend><?= $t('checkout.delivery_method') ?></legend>
        <label>
          <input type="radio" name="mode" value="shipping" checked>
          <?= $t('checkout.shipping') ?>
        </label>
        <label>
          <input type="radio" name="mode" value="pickup">
          <?= $t('checkout.pickup') ?>
        </label>
      </fieldset>

      <fieldset class="commande-adresse">
        <legend><?= $t('checkout.shipping_address') ?></legend>
        <label for="adresse"><?= $t('checkout.address') ?></label>
        <input type="text" id="adresse" name="adresse" maxlength="190">

        <label for="complement"><?= $t('checkout.address_line2') ?></label>
        <input type="text" id="complement" name="complement" maxlength="190">

        <label for="code_postal"><?= $t('checkout.postal_code') ?></label>
        <input type="text" id="code_postal" name="code_postal" maxlength="16">

        <label for="ville"><?= $t('checkout.city') ?></label>
        <input type="text" id="ville" name="ville" maxlength="120">

        <label for="pays"><?= $t('checkout.country') ?></label>
        <input type="text" id="pays" name="pays" value="FR" maxlength="2">
      </fieldset>

      <label for="note"><?= $t('checkout.note') ?></label>
      <textarea id="note" name="note" maxlength="500" rows="3"></textarea>

      <label class="commande-cgv">
        <input type="checkbox" name="cgv" value="on" required>
        <span>
          <?= $t('checkout.accept_terms') ?>
          <a href="<?= attr($cgvUrl) ?>"><?= $t('checkout.read') ?></a>
          <a href="<?= attr($cgvPdfUrl) ?>" target="_blank" rel="noopener"><?= $t('checkout.pdf') ?></a>
        </span>
      </label>

      <button type="submit" class="btn btn-plein">
        <?= $t('checkout.pay') ?>
      </button>
    </form>
  </div>
</main>
