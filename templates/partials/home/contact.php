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
  <?php if (isset($data['ctas']['contact'])) : ?>
    <?= $partial('partials/cta', $data['ctas']['contact']) ?>
  <?php endif; ?>
</section>
<?php endif; ?>
