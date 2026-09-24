<?php

/**
 * Espace client — demande du lien de connexion (revue du 2026-09-24).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
$csrf = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
?>
<article class="wrap page-editoriale compte">
  <header class="page-tete">
    <h1><?= $t('nav.account') ?></h1>
  </header>

  <div class="page-corps">
    <?php if (($data['requested'] ?? false) === true) : ?>
      <p role="status"><?= $t('account.link_sent') ?></p>
    <?php elseif (($data['throttled'] ?? false) === true) : ?>
      <p role="alert"><?= $t('account.throttled') ?></p>
    <?php elseif (($data['invalidLink'] ?? false) === true) : ?>
      <p role="alert"><?= $t('account.invalid_link') ?></p>
    <?php endif; ?>

    <p><?= $t('account.login_intro') ?></p>

    <form method="post" action="<?= attr($url->route('account.request', ['locale' => $locale->value])) ?>" class="contact-form">
      <input type="hidden" name="_token" value="<?= attr($csrf) ?>">
      <label for="email"><?= $t('account.email') ?></label>
      <input type="email" id="email" name="email" required maxlength="190" autocomplete="email">
      <button type="submit" class="btn btn-plein"><?= $t('account.send_link') ?></button>
    </form>
  </div>
</article>
