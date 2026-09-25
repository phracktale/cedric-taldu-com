<?php

/**
 * Section « Formulaire » du modèle « Contact » (retours du 2026-09-25).
 *
 * Reçoit les données de la page entière ; l'ordre et la présence des
 * sections viennent du modèle composé en back-office. Voir front/contact.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
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
<form method="post" action="<?= attr($submitUrl) ?>" class="contact-form">
  <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">
  <input type="hidden" name="<?= attr($timestampField) ?>" value="<?= attr($timestamp) ?>">
  <?php if ($artworkSlug !== null) : ?>
    <input type="hidden" name="oeuvre" value="<?= attr($artworkSlug) ?>">
  <?php endif; ?>

  <?php // Champ appât : hors écran, jamais atteignable au clavier. ?>
  <div aria-hidden="true" class="pot-de-miel" tabindex="-1">
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

  <?php // Revue du 2026-09-24 : consentement explicite, newsletter facultative et non précochée. ?>
  <label class="case">
    <input type="checkbox" name="rgpd" value="1" required>
    <span>
      <?= $t('contact.consent') ?>
      <a href="<?= attr($url->route('page.privacy', ['locale' => $locale->value])) ?>"><?= $t('contact.learn_more') ?></a>
    </span>
  </label>
  <?php if (isset($errors['rgpd'])) : ?>
    <span class="champ-erreur" role="alert"><?= e($errors['rgpd']) ?></span>
  <?php endif; ?>

  <label class="case">
    <input type="checkbox" name="newsletter" value="1">
    <span><?= $t('newsletter.consent') ?></span>
  </label>

  <button type="submit" class="btn btn-plein">
    <?= $t('contact.send') ?>
  </button>
</form>
