<?php

/**
 * Désinscription de la newsletter (revue du 2026-09-24) : confirmation (POST),
 * résultat, ou lien invalide.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
$etat = is_string($data['state'] ?? null) ? $data['state'] : 'invalid';
$email = is_string($data['email'] ?? null) ? $data['email'] : '';
$jeton = is_string($data['token'] ?? null) ? $data['token'] : '';
$csrf = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
?>
<article class="wrap page-editoriale">
  <header class="page-tete">
    <h1><?= $t('newsletter.unsubscribe_title') ?></h1>
  </header>

  <div class="page-corps">
    <?php if ($etat === 'confirm') : ?>
      <p><?= $t('newsletter.unsubscribe_intro', ['email' => $email]) ?></p>
      <form method="post" action="<?= attr($url->route('newsletter.unsubscribe', ['locale' => $locale->value])) ?>">
        <input type="hidden" name="_token" value="<?= attr($csrf) ?>">
        <input type="hidden" name="email" value="<?= attr($email) ?>">
        <input type="hidden" name="jeton" value="<?= attr($jeton) ?>">
        <p class="cta-row cta-row--gauche">
          <button type="submit" class="btn btn-plein"><?= $t('newsletter.unsubscribe_confirm') ?></button>
        </p>
      </form>
    <?php elseif ($etat === 'done') : ?>
      <p role="status"><?= $t('newsletter.unsubscribed') ?></p>
    <?php else : ?>
      <p role="alert"><?= $t('newsletter.invalid_link') ?></p>
    <?php endif; ?>
  </div>
</article>
