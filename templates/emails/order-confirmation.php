<?php

/**
 * Récapitulatif de commande envoyé au client (03-boutique §7).
 *
 * @var App\Repository\PersistedOrder $order
 * @var App\Domain\Locale             $locale
 * @var string                        $consultationUrl
 * @var string|null                   $legalMention
 * @var array<string, string>         $strings
 */



declare(strict_types=1);

/** @var App\Repository\PersistedOrder $order */
$order = $data['order'];
/** @var App\Domain\Locale $locale */
$locale = $data['locale'];
/** @var string $consultationUrl */
$consultationUrl = $data['consultationUrl'];
/** @var string|null $legalMention */
$legalMention = $data['legalMention'];
/** @var array<string, string> $strings */
$strings = $data['strings'];
?>
<h1 style="margin:0 0 8px;font-size:22px;"><?= e($strings['greeting']) ?>, <?= e($order->customerName) ?></h1>
<p style="margin:0 0 24px;"><?= e($strings['intro']) ?></p>

<p style="margin:0 0 16px;font-size:15px;">
<strong><?= e($strings['order']) ?></strong> <?= e($order->reference) ?>
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

<p style="margin:32px 0 0;">
<a href="<?= attr($consultationUrl) ?>" style="color:#8a5a2b;"><?= e($strings['consult']) ?></a>
</p>

<?php if ($legalMention !== null) : ?>
<p style="margin:24px 0 0;font-size:13px;color:#6b655c;"><?= e($legalMention) ?></p>
<?php endif; ?>

<p style="margin:16px 0 0;font-size:13px;color:#6b655c;"><?= e($strings['withdrawal']) ?></p>
