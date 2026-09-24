<?php

/**
 * Espace client — détail d'une commande (revue du 2026-09-24) : articles,
 * adresses de livraison et de facturation, paiement, suivi, facture.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Order\Address;
use App\Repository\PersistedOrder;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var PersistedOrder $commande */
$commande = $data['order'];

$adresse = static function (?Address $a): string {
    if ($a === null) {
        return '';
    }

    return implode("\n", array_filter([$a->line1, $a->line2, trim($a->postalCode . ' ' . $a->city), $a->country]));
};
?>
<article class="wrap page-editoriale compte">
  <header class="page-tete">
    <p><a href="<?= attr($url->route('account.index', ['locale' => $locale->value])) ?>">← <?= $t('account.back') ?></a></p>
    <h1><?= $t('account.order') ?> <?= e($commande->reference) ?></h1>
    <p class="page-langue"><?= e(dateLong($commande->createdAt, $locale)) ?> · <?= e($commande->status->label($locale)) ?></p>
  </header>

  <section class="compte-bloc">
    <h2><?= $t('account.items') ?></h2>
    <ul class="commande-lignes">
      <?php foreach ($commande->lines as $ligne) : ?>
      <li>
        <span><?= e($ligne->label) ?> × <?= e($ligne->quantity) ?></span>
        <span><?= e(money($ligne->total, $locale)) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="commande-detail"><span><?= $t('cart.subtotal') ?></span><span><?= e(money($commande->subtotal, $locale)) ?></span></p>
    <p class="commande-detail"><span><?= $t('checkout.shipping_cost') ?></span><span><?= e(money($commande->shipping, $locale)) ?></span></p>
    <p class="commande-total"><span><?= $t('checkout.total') ?></span><span><?= e(money($commande->total, $locale)) ?></span></p>
    <?php if ($commande->legalMention() !== null) : ?>
      <p class="champ-aide"><?= e((string) $commande->legalMention()) ?></p>
    <?php endif; ?>
  </section>

  <section class="compte-bloc compte-adresses">
    <div>
      <h2><?= $t('account.shipping_address') ?></h2>
      <p><?= e($commande->shippingMethod->label($locale)) ?></p>
      <?php if ($commande->shippingAddress !== null) : ?>
        <p class="adresse-bloc"><?= e($adresse($commande->shippingAddress)) ?></p>
      <?php endif; ?>
    </div>
    <div>
      <h2><?= $t('account.billing_address') ?></h2>
      <?php if ($commande->billingAddress !== null) : ?>
        <p class="adresse-bloc"><?= e($adresse($commande->billingAddress)) ?></p>
      <?php else : ?>
        <p><?= $t('account.billing_same') ?></p>
      <?php endif; ?>
    </div>
  </section>

  <section class="compte-bloc">
    <h2><?= $t('account.payment') ?></h2>
    <?php if ($commande->paidAt !== null) : ?>
      <p><?= $t('account.paid_on') ?> <?= e(dateLong($commande->paidAt, $locale)) ?> — <?= $t('checkout.payment_secure') ?></p>
    <?php endif; ?>
    <?php if ($commande->paymentReference !== null) : ?>
      <p><?= $t('account.payment_ref') ?> : <code><?= e($commande->paymentReference) ?></code></p>
    <?php endif; ?>
    <?php if ($commande->trackingNumber !== null) : ?>
      <p><?= $t('account.tracking') ?> : <?= e((string) $commande->trackingCarrier) ?> <?= e($commande->trackingNumber) ?></p>
    <?php endif; ?>
    <?php if (($data['invoiceable'] ?? false) === true) : ?>
      <p class="cta-row cta-row--gauche">
        <a class="btn btn-plein" href="<?= attr($url->route('account.invoice', ['locale' => $locale->value, 'reference' => $commande->reference])) ?>"><?= $t('account.invoice') ?></a>
      </p>
    <?php endif; ?>
  </section>
</article>
