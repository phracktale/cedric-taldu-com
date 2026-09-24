<?php

/**
 * Lien de connexion à l'espace client (revue du 2026-09-24).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

/** @var App\Domain\Locale $locale */
$locale = $data['locale'];
$lien = is_string($data['link'] ?? null) ? $data['link'] : '';
$fr = $locale === App\Domain\Locale::Fr;
?>
<h1 style="margin:0 0 16px;font-size:22px;"><?= e($fr ? 'Votre espace client' : 'Your customer area') ?></h1>
<p style="margin:0 0 16px;">
<?= e($fr
    ? 'Pour consulter vos commandes et vos factures, ouvrez ce lien. Il est valable 20 minutes et ne sert qu’une fois.'
    : 'To view your orders and invoices, open this link. It is valid for 20 minutes and can be used once.') ?>
</p>
<p style="margin:0 0 24px;"><a href="<?= attr($lien) ?>"><?= e($lien) ?></a></p>
<p style="margin:0;color:#8c8983;">
<?= e($fr
    ? 'Si vous n’êtes pas à l’origine de cette demande, ignorez simplement ce message.'
    : 'If you did not request this, simply ignore this message.') ?>
</p>
