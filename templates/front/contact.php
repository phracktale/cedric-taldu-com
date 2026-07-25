<?php

/**
 * Formulaire de contact, général ou rattaché à une œuvre (02-front §6).
 *
 * Le champ appât `site_web` est masqué hors écran (jamais display:none seul),
 * aria-hidden + tabindex=-1 (06-securite §6.1). Le champ caché d'horodatage
 * porte la signature émise à l'affichage (§6.2). Aucun gestionnaire inline.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Catalog\Artwork;
use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var string $submitUrl */
$submitUrl = $data['submitUrl'];
/** @var string $honeypot */
$honeypot = $data['honeypot'];
/** @var string $timestampField */
$timestampField = $data['timestampField'];
/** @var string $timestamp */
$timestamp = $data['timestamp'];
/** @var Artwork|null $artwork */
$artwork = $data['artwork'] ?? null;
/** @var string|null $artworkSlug */
$artworkSlug = $data['artworkSlug'] ?? null;
$sent = ($data['sent'] ?? false) === true;
/** @var string|null $error */
$error = is_string($data['error'] ?? null) ? $data['error'] : null;
/** @var array<string, string> $errors */
$errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
/** @var array<string, string> $values */
$values = is_array($data['values'] ?? null) ? $data['values'] : [];
/** @var string $csrfToken */
$csrfToken = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

?>
<div class="wrap contact">
  <h1><?= $t('nav.contact') ?></h1>

  <?php if ($sent) : ?>
    <p class="contact-succes" role="status">
      <?= $t('contact.success') ?>
    </p>
  <?php endif; ?>

  <?php if ($artwork !== null) : ?>
    <p class="contact-oeuvre">
      <?= $t('contact.about_artwork') ?>
      <strong><?= e($artwork->title($locale)) ?></strong>
    </p>
  <?php endif; ?>

  <?php if ($error !== null) : ?>
    <p class="contact-erreur" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= attr($submitUrl) ?>" class="contact-form">
    <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">
    <input type="hidden" name="<?= attr($timestampField) ?>" value="<?= attr($timestamp) ?>">
    <?php if ($artworkSlug !== null) : ?>
      <input type="hidden" name="oeuvre" value="<?= attr($artworkSlug) ?>">
    <?php endif; ?>

    <?php // Champ appât : hors écran, jamais atteignable au clavier. ?>
    <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;" tabindex="-1">
      <label for="<?= attr($honeypot) ?>"><?= $t('form.do_not_fill') ?></label>
      <input type="text" id="<?= attr($honeypot) ?>" name="<?= attr($honeypot) ?>"
             tabindex="-1" autocomplete="off">
    </div>

    <label for="nom"><?= $t('contact.name') ?></label>
    <input type="text" id="nom" name="nom" required maxlength="160"
           value="<?= attr($values['nom'] ?? '') ?>">
    <?php if (isset($errors['nom'])) : ?>
      <span class="champ-erreur" role="alert"><?= e($errors['nom']) ?></span>
    <?php endif; ?>

    <label for="email"><?= $t('contact.email') ?></label>
    <input type="email" id="email" name="email" required maxlength="190"
           value="<?= attr($values['email'] ?? '') ?>">
    <?php if (isset($errors['email'])) : ?>
      <span class="champ-erreur" role="alert"><?= e($errors['email']) ?></span>
    <?php endif; ?>

    <label for="message"><?= $t('contact.message') ?></label>
    <textarea id="message" name="message" required maxlength="3000" rows="8"><?= e($values['message'] ?? '') ?></textarea>
    <?php if (isset($errors['message'])) : ?>
      <span class="champ-erreur" role="alert"><?= e($errors['message']) ?></span>
    <?php endif; ?>

    <button type="submit" class="btn btn-plein">
      <?= $t('contact.send') ?>
    </button>
  </form>

  <p class="contact-rgpd">
    <?= $t('contact.rgpd') ?>
    <a href="<?= attr($url->route('page.privacy', ['locale' => $locale->value])) ?>">
      <?= $t('contact.learn_more') ?>
    </a>
  </p>
</div>
