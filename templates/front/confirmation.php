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

$estFr = $locale === Locale::Fr;
$enAttente = $order->status === OrderStatus::Pending;
?>
<main class="confirmation" id="contenu"<?php if ($enAttente) : ?> data-confirmation-poll<?php endif; ?>>
  <div class="wrap">
    <?php if ($enAttente) : ?>
      <h1><?= e($estFr ? 'Paiement en cours de confirmation' : 'Payment being confirmed') ?></h1>
      <p class="confirmation-attente" role="status">
        <?= e($estFr
            ? 'Votre paiement est en cours de traitement. Cette page se met à jour automatiquement.'
            : 'Your payment is being processed. This page refreshes automatically.') ?>
      </p>
    <?php elseif ($order->status === OrderStatus::Paid || $order->status === OrderStatus::Shipped) : ?>
      <h1><?= e($estFr ? 'Merci pour votre commande' : 'Thank you for your order') ?></h1>
      <p><?= e($estFr
          ? 'Votre paiement a bien été reçu. Un e-mail de confirmation vous a été envoyé.'
          : 'Your payment has been received. A confirmation email is on its way.') ?></p>
    <?php else : ?>
      <h1><?= e($estFr ? 'Commande non aboutie' : 'Order not completed') ?></h1>
      <p><?= e($estFr
          ? 'Le paiement n’a pas abouti. Aucun montant ne vous a été prélevé.'
          : 'The payment did not go through. You have not been charged.') ?></p>
    <?php endif; ?>

    <p class="confirmation-reference">
      <strong><?= e($estFr ? 'Référence' : 'Reference') ?></strong> <?= e($order->reference) ?>
    </p>

    <p class="confirmation-total">
      <strong><?= e($estFr ? 'Total' : 'Total') ?></strong> <?= e(money($order->total, $locale)) ?>
    </p>

    <?php if ($order->legalMention() !== null) : ?>
      <p class="confirmation-tva"><?= e($order->legalMention()) ?></p>
    <?php endif; ?>

    <p>
      <a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>">
        <?= e($estFr ? 'Retour à l’accueil' : 'Back to home') ?>
      </a>
    </p>
  </div>
</main>
