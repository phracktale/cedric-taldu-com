<?php

/**
 * Notification de commande envoyée à l'artiste (03-boutique §7).
 *
 * @var App\Repository\PersistedOrder $order
 * @var App\Domain\Locale             $locale
 * @var array<string, string>         $strings
 */



declare(strict_types=1);

/** @var App\Repository\PersistedOrder $order */
$order = $data['order'];
/** @var App\Domain\Locale $locale */
$locale = $data['locale'];
/** @var array<string, string> $strings */
$strings = $data['strings'];
?>
<h1 style="margin:0 0 16px;font-size:22px;">Nouvelle commande <?= e($order->reference) ?></h1>

<p style="margin:0 0 4px;"><strong><?= e($strings['customer']) ?></strong></p>
<p style="margin:0 0 24px;">
<?= e($order->customerName) ?><br>
<?= e($order->customerEmail) ?>
<?php if ($order->customerPhone !== null) : ?><br><?= e($order->customerPhone) ?><?php endif; ?>
</p>

<?= $partial('emails/order-lines', ['order' => $order, 'locale' => $locale, 'strings' => $strings]) ?>

<?php if ($order->shippingAddress !== null) : ?>
<p style="margin:24px 0 4px;"><strong><?= e($strings['shippingAddress']) ?></strong></p>
<p style="margin:0;">
<?= e($order->shippingAddress->line1) ?><br>
<?php if ($order->shippingAddress->line2 !== null) : ?><?= e($order->shippingAddress->line2) ?><br><?php endif; ?>
<?= e($order->shippingAddress->postalCode) ?> <?= e($order->shippingAddress->city) ?><br>
<?= e($order->shippingAddress->country) ?>
</p>
<?php else : ?>
<p style="margin:24px 0 0;"><?= e($strings['pickup']) ?></p>
<?php endif; ?>

<?php if ($order->customerNote !== null) : ?>
<p style="margin:24px 0 4px;"><strong>Note du client</strong></p>
<p style="margin:0;"><?= e($order->customerNote) ?></p>
<?php endif; ?>
