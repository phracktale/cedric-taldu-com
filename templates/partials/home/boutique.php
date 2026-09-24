<?php

/**
 * Accueil — BOUTIQUE : bande contrastée + porte d'entrée vers les œuvres.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$shop = $data['shop'];
/** @var callable $texte */
$texte = $data['texte'];
?>
<?php if ($texte($shop, 'title') !== null) : ?>
<section class="boutique" id="boutique">
  <div class="wrap">
    <?php if ($texte($shop, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($shop, 'eyebrow')) ?></p><?php endif; ?>
    <h2><?= e($texte($shop, 'title')) ?></h2>
    <hr class="stipple">
    <?php if ($texte($shop, 'text') !== null) : ?><p><?= e($texte($shop, 'text')) ?></p><?php endif; ?>
    <?php if (isset($data['ctas']['boutique'])) : ?>
      <?= $partial('partials/cta', $data['ctas']['boutique']) ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
