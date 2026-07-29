<?php

/**
 * Tunnel de commande (03-boutique §3, étape 2).
 *
 * Un seul écran, trois sections : coordonnées, livraison (mode + tarif +
 * adresse), récapitulatif (montants, réception estimée, paiement). AUCUN champ
 * de prix : les montants affichés sont indicatifs, recalculés côté serveur au
 * paiement (§8.2). Le champ appât `site_web` est masqué hors écran, jamais en
 * display:none seul, avec aria-hidden + tabindex=-1 (06-securite §6.1).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Money;
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

/** @var Money|null $shippingPrice */
$shippingPrice = $data['shippingPrice'] ?? null;
$shippingOnRequest = ($data['shippingOnRequest'] ?? false) === true;
/** @var Money|null $totalShipping */
$totalShipping = $data['totalShipping'] ?? null;
/** @var DateTimeImmutable $deliveryFrom */
$deliveryFrom = $data['deliveryFrom'];
/** @var DateTimeImmutable $deliveryTo */
$deliveryTo = $data['deliveryTo'];

$cgvUrl = $url->route('page.terms', ['locale' => $locale->value]);
// Version PDF des CGV, par langue (servie sous public/assets/documents/).
$cgvPdfUrl = $url->asset('documents/cgv-cedric-taldu-' . $locale->value . '.pdf');

// Montants affichés, par mode de remise. L'expédition peut être « sur devis »
// quand le poids est inconnu : on ne compose alors aucun total.
$estFr = $locale === Locale::Fr;
$shippingText = $shippingOnRequest ? $t('checkout.on_request') : money($shippingPrice, $locale);
$totalShippingText = $totalShipping === null ? $t('checkout.on_request') : money($totalShipping, $locale);
$pickupText = $t('checkout.free');
$totalPickupText = money($valuation->subtotal, $locale);

// Fenêtre de réception, formatée dans la langue de la page.
$mois = $estFr
    ? [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre']
    : [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$jour = static function (DateTimeImmutable $d) use ($mois, $estFr): string {
    $n = (int) $d->format('j');
    $m = $mois[(int) $d->format('n')];

    return $estFr ? $n . ' ' . $m : $m . ' ' . $n;
};
?>
<main class="commande" id="contenu">
  <div class="wrap">
    <h1><?= $t('checkout.title') ?></h1>

    <?php if ($error !== null) : ?>
      <p class="commande-erreur" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= attr($submitUrl) ?>" class="commande-form" data-commande>
      <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">

      <?php // Champ appât : positionné hors écran, jamais atteignable au clavier. ?>
      <div aria-hidden="true" class="pot-de-miel" tabindex="-1">
        <label for="<?= attr($honeypot) ?>"><?= $t('form.do_not_fill') ?></label>
        <input type="text" id="<?= attr($honeypot) ?>" name="<?= attr($honeypot) ?>"
               tabindex="-1" autocomplete="off">
      </div>

      <!-- 1 — Coordonnées -->
      <section class="commande-section">
        <h2><span class="commande-num">1</span> <?= $t('checkout.your_details') ?></h2>
        <label for="nom"><?= $t('checkout.name') ?></label>
        <input type="text" id="nom" name="nom" required maxlength="160">

        <label for="email"><?= $t('checkout.email') ?></label>
        <input type="email" id="email" name="email" required maxlength="190">

        <label for="telephone"><?= $t('checkout.phone') ?></label>
        <input type="tel" id="telephone" name="telephone" maxlength="40">
      </section>

      <!-- 2 — Livraison -->
      <section class="commande-section">
        <h2><span class="commande-num">2</span> <?= $t('checkout.delivery') ?></h2>

        <div class="commande-modes">
          <label class="commande-mode">
            <input type="radio" name="mode" value="shipping" checked
                   data-prix="<?= attr($shippingText) ?>" data-total="<?= attr($totalShippingText) ?>" data-mode="shipping">
            <span class="commande-mode-nom"><?= $t('checkout.shipping') ?></span>
            <span class="commande-mode-prix"><?= e($shippingText) ?></span>
          </label>
          <label class="commande-mode">
            <input type="radio" name="mode" value="pickup"
                   data-prix="<?= attr($pickupText) ?>" data-total="<?= attr($totalPickupText) ?>" data-mode="pickup">
            <span class="commande-mode-nom"><?= $t('checkout.pickup') ?></span>
            <span class="commande-mode-prix"><?= e($pickupText) ?></span>
          </label>
        </div>

        <div class="commande-adresse" data-commande-adresse>
          <label for="adresse"><?= $t('checkout.address') ?></label>
          <input type="text" id="adresse" name="adresse" maxlength="190">

          <label for="complement"><?= $t('checkout.address_line2') ?></label>
          <input type="text" id="complement" name="complement" maxlength="190">

          <div class="commande-ligne">
            <span class="champ">
              <label for="code_postal"><?= $t('checkout.postal_code') ?></label>
              <input type="text" id="code_postal" name="code_postal" maxlength="16">
            </span>
            <span class="champ champ--large">
              <label for="ville"><?= $t('checkout.city') ?></label>
              <input type="text" id="ville" name="ville" maxlength="120">
            </span>
            <span class="champ">
              <label for="pays"><?= $t('checkout.country') ?></label>
              <input type="text" id="pays" name="pays" value="FR" maxlength="2">
            </span>
          </div>
          <p class="champ-aide"><?= $t('checkout.shipping_note') ?></p>
        </div>
      </section>

      <!-- 3 — Récapitulatif et paiement -->
      <section class="commande-section commande-bilan">
        <h2><span class="commande-num">3</span> <?= $t('checkout.summary') ?></h2>

        <ul class="commande-lignes">
          <?php foreach ($valuation->lines as $line) : ?>
            <li>
              <span><?= e($line->item->label) ?> × <?= e($line->quantity) ?></span>
              <span><?= e(money($line->total, $locale)) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>

        <p class="commande-detail">
          <span><?= $t('cart.subtotal') ?></span>
          <span><?= e(money($valuation->subtotal, $locale)) ?></span>
        </p>
        <p class="commande-detail">
          <span><?= $t('checkout.shipping_cost') ?></span>
          <span data-recap-port><?= e($shippingText) ?></span>
        </p>
        <p class="commande-total">
          <span><?= $t('checkout.total') ?></span>
          <span data-recap-total><?= e($totalShippingText) ?></span>
        </p>

        <p class="commande-reception" data-quand-expedition><?= $t('checkout.delivery_estimate', ['from' => $jour($deliveryFrom), 'to' => $jour($deliveryTo)]) ?></p>
        <p class="commande-reception" data-quand-retrait hidden><?= $t('checkout.pickup_notice') ?></p>

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

        <p class="commande-paiement"><?= $t('checkout.payment_secure') ?> Visa · Mastercard · CB.</p>

        <button type="submit" class="btn btn-plein commande-payer">
          <?= $t('checkout.pay') ?><span data-recap-bouton> — <?= e($totalShippingText) ?></span>
        </button>
      </section>
    </form>
  </div>
</main>
