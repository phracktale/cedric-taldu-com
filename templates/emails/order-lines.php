<?php

/**
 * Tableau des lignes et des totaux, partagé par les courriels.
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
<table style="width:100%;border-collapse:collapse;font-size:14px;">
<tr style="text-align:left;border-bottom:1px solid #e4e0d8;">
<th style="padding:8px 0;"><?= e($strings['item']) ?></th>
<th style="padding:8px 0;text-align:center;"><?= e($strings['quantity']) ?></th>
<th style="padding:8px 0;text-align:right;"><?= e($strings['amount']) ?></th>
</tr>
<?php foreach ($order->lines as $line) : ?>
<tr style="border-bottom:1px solid #f0ede7;">
<td style="padding:8px 0;">
<?= e($line->label) ?>
<?php if ($line->editionNumber !== null) : ?>
<br><span style="font-size:12px;color:#6b655c;"><?= e($strings['edition']) ?> <?= e($line->editionNumber) ?></span>
<?php endif; ?>
</td>
<td style="padding:8px 0;text-align:center;"><?= e($line->quantity) ?></td>
<td style="padding:8px 0;text-align:right;"><?= e(money($line->total, $locale)) ?></td>
</tr>
<?php endforeach; ?>
<tr>
<td colspan="2" style="padding:8px 0;"><?= e($strings['subtotal']) ?></td>
<td style="padding:8px 0;text-align:right;"><?= e(money($order->subtotal, $locale)) ?></td>
</tr>
<tr>
<td colspan="2" style="padding:4px 0;"><?= e($strings['shipping']) ?></td>
<td style="padding:4px 0;text-align:right;"><?= e(money($order->shipping, $locale)) ?></td>
</tr>
<tr style="border-top:1px solid #e4e0d8;">
<td colspan="2" style="padding:8px 0;"><strong><?= e($strings['total']) ?></strong></td>
<td style="padding:8px 0;text-align:right;"><strong><?= e(money($order->total, $locale)) ?></strong></td>
</tr>
</table>
