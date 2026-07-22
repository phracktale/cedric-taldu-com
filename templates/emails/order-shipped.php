<?php

/**
 * Avis d'expédition envoyé au client (03-boutique §7).
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
/** @var array<string, string> $strings */
$strings = $data['strings'];
?>
<h1 style="margin:0 0 8px;font-size:22px;"><?= e($strings['shippedIntro']) ?></h1>
<p style="margin:0 0 24px;font-size:15px;">
<strong><?= e($strings['order']) ?></strong> <?= e($order->reference) ?>
</p>

<?php if ($order->trackingCarrier !== null) : ?>
<p style="margin:0 0 4px;"><strong><?= e($strings['carrier']) ?></strong> <?= e($order->trackingCarrier) ?></p>
<?php endif; ?>
<?php if ($order->trackingNumber !== null) : ?>
<p style="margin:0 0 24px;"><strong><?= e($strings['tracking']) ?></strong> <?= e($order->trackingNumber) ?></p>
<?php endif; ?>

<?= $partial('emails/order-lines', ['order' => $order, 'locale' => $locale, 'strings' => $strings]) ?>

<p style="margin:32px 0 0;">
<a href="<?= attr($consultationUrl) ?>" style="color:#8a5a2b;"><?= e($strings['consult']) ?></a>
</p>

<p style="margin:16px 0 0;font-size:13px;color:#6b655c;"><?= e($strings['withdrawal']) ?></p>
