<?php

/**
 * Page de retour après paiement (03-boutique §3, étape 3).
 *
 * Elle N'ACCORDE JAMAIS RIEN : elle lit l'état de la commande. Si le webhook
 * n'est pas encore arrivé, la commande est encore `pending` et la page annonce
 * un paiement en cours de confirmation, en s'actualisant discrètement. Le
 * décrément de stock, la vente et les e-mails restent l'affaire du seul webhook.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Order\OrderStatus;
use App\Repository\PersistedOrder;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var PersistedOrder $order */
$order = $data['order'];
/** @var string $nonce */
$nonce = $data['nonce'];

$enAttente = $order->status === OrderStatus::Pending;
?>
<main class="confirmation" id="contenu"<?php if ($enAttente) : ?> data-confirmation-poll<?php endif; ?>>
  <div class="wrap">
    <?php if ($enAttente) : ?>
      <h1><?= $t('confirmation.pending_title') ?></h1>
      <p class="confirmation-attente" role="status">
        <?= $t('confirmation.pending_text') ?>
      </p>
    <?php elseif ($order->status === OrderStatus::Paid || $order->status === OrderStatus::Shipped) : ?>
      <h1><?= $t('confirmation.paid_title') ?></h1>
      <p><?= $t('confirmation.paid_text') ?></p>
    <?php else : ?>
      <h1><?= $t('confirmation.failed_title') ?></h1>
      <p><?= $t('confirmation.failed_text') ?></p>
    <?php endif; ?>

    <p class="confirmation-reference">
      <strong><?= $t('confirmation.reference') ?></strong> <?= e($order->reference) ?>
    </p>

    <p class="confirmation-total">
      <strong><?= $t('confirmation.total') ?></strong> <?= e(money($order->total, $locale)) ?>
    </p>

    <?php if ($order->legalMention() !== null) : ?>
      <p class="confirmation-tva"><?= e($order->legalMention()) ?></p>
    <?php endif; ?>

    <p>
      <a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>">
        <?= $t('confirmation.back_home') ?>
      </a>
    </p>
  </div>
</main>
