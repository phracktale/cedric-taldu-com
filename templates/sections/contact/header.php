<?php

/**
 * Section « Titre et introduction » du modèle « Contact » (retours du 2026-09-25).
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
