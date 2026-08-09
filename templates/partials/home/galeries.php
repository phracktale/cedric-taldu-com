<?php

/**
 * Accueil — GALERIES : une carte par rubrique publiée, alimentée par la base.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = $data['locale'];
/** @var list<App\Domain\Catalog\Category> $rubriques */
$rubriques = $data['rubriques'];
?>
<?php if ($rubriques !== []) : ?>
<section class="galeries wrap" id="galeries">
  <p class="eyebrow"><?= $t('home.galleries_eyebrow') ?></p>
  <h2><?= $t('home.galleries_title') ?></h2>
  <div class="gal-grid<?php if (count($rubriques) > 2) : ?> auto<?php endif; ?>">
    <?php foreach ($rubriques as $rubrique) : ?>
    <a class="gal-card" href="<?= attr($url->route('category.show', ['locale' => $locale->value, 'slug' => $rubrique->slug($locale)->value])) ?>">
      <h3><?= e($rubrique->title($locale)) ?></h3>
      <?php if ($rubrique->description($locale) !== null) : ?>
      <p><?= e(mb_substr(trim(strip_tags($rubrique->description($locale))), 0, 220)) ?></p>
      <?php endif; ?>
      <span class="lien"><?= $t('home.gallery_link', ['name' => mb_strtolower($rubrique->title($locale))]) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
