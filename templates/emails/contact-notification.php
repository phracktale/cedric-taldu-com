<?php

/**
 * Notification à l'artiste d'un message de contact (04-back-office §10).
 *
 * Le corps du visiteur est du TEXTE BRUT : il est échappé par e() et ses sauts
 * de ligne préservés par `white-space: pre-wrap`, jamais réinterprété en HTML.
 *
 * @var App\Domain\Contact\ContactMessage $message
 * @var string|null                       $artworkTitle
 * @var string|null                       $adminUrl
 */



declare(strict_types=1);

/** @var App\Domain\Contact\ContactMessage $message */
$message = $data['message'];
/** @var string|null $artworkTitle */
$artworkTitle = $data['artworkTitle'] ?? null;
/** @var string|null $adminUrl */
$adminUrl = $data['adminUrl'] ?? null;
?>
<h1 style="margin:0 0 16px;font-size:22px;">
<?php if ($artworkTitle !== null) : ?>
Question sur « <?= e($artworkTitle) ?> »
<?php else : ?>
Nouveau message de contact
<?php endif; ?>
</h1>

<p style="margin:0 0 4px;"><strong>Expéditeur</strong></p>
<p style="margin:0 0 24px;">
<?= e($message->senderName) ?><br>
<?= e($message->senderEmail) ?>
</p>

<p style="margin:0 0 4px;"><strong>Message</strong></p>
<div style="margin:0;white-space:pre-wrap;"><?= e($message->body) ?></div>

<?php if ($adminUrl !== null) : ?>
<p style="margin:24px 0 0;">
<a href="<?= attr($adminUrl) ?>" style="color:#8a5a2b;">Ouvrir dans la boîte de réception</a>
</p>
<?php endif; ?>
