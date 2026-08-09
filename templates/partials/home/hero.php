<?php

/**
 * Accueil — section HERO : le H1 SEO, la baseline, une porte d'entrée.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = $data['locale'];
$hero = $data['hero'];
$rubriques = $data['rubriques'];
/** @var callable $texte */
$texte = $data['texte'];
?>
<section class="hero wrap">
  <?php if ($texte($hero, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($hero, 'eyebrow')) ?></p><?php endif; ?>
  <h1><?= e($texte($hero, 'title') ?? 'Cédric Taldu') ?></h1>
  <?php if ($texte($hero, 'baseline') !== null) : ?><p class="baseline"><?= e($texte($hero, 'baseline')) ?></p><?php endif; ?>
  <?php if ($rubriques !== []) : ?>
  <div class="cta-row">
    <a class="btn btn-plein" href="#galeries"><?php if ($texte($hero, 'cta') !== null) : ?><?= e($texte($hero, 'cta')) ?><?php else : ?><?= $t('home.hero_cta') ?><?php endif; ?></a>
  </div>
  <?php endif; ?>
</section>
