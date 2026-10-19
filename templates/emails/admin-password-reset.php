<?php

/**
 * Lien « mot de passe oublié » de l'administration (2026-10-19).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$lien = is_string($data['link'] ?? null) ? $data['link'] : '';
$minutes = App\Service\Auth\PasswordReset::LIFETIME_MINUTES;
?>
<h1 style="margin:0 0 16px;font-size:22px;"><?= e('Réinitialisation de votre mot de passe') ?></h1>
<p style="margin:0 0 16px;">
<?= e('Pour choisir un nouveau mot de passe d’administration, ouvrez ce lien. Il est valable ' . $minutes . ' minutes et ne sert qu’une fois.') ?>
</p>
<p style="margin:0 0 24px;"><a href="<?= attr($lien) ?>"><?= e($lien) ?></a></p>
<p style="margin:0;color:#8c8983;">
<?= e('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.') ?>
</p>
