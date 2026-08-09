<?php

/**
 * Accueil — CONTACT : invitation + bouton vers le formulaire.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = $data['locale'];
$contact = $data['contact'];
/** @var callable $texte */
$texte = $data['texte'];
?>
<?php if ($texte($contact, 'title') !== null) : ?>
<section class="contact wrap" id="contact">
  <p class="eyebrow"><?php if ($texte($contact, 'eyebrow') !== null) : ?><?= e($texte($contact, 'eyebrow')) ?><?php else : ?><?= $t('nav.contact') ?><?php endif; ?></p>
  <h2><?= e($texte($contact, 'title')) ?></h2>
  <?php if ($texte($contact, 'text') !== null) : ?><p><?= e($texte($contact, 'text')) ?></p><?php endif; ?>
  <p class="cta-row">
    <a class="btn btn-vide" href="<?= attr($url->route('contact.form', ['locale' => $locale->value])) ?>">
      <?= $t('home.contact_cta') ?>
    </a>
  </p>
</section>
<?php endif; ?>
