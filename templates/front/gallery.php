<?php

/**
 * Page mère « Galerie » (revue du 2026-09-24) : les sous-galeries publiées,
 * dans l'ordre choisi en back-office, et l'accès à toutes les œuvres.
 *
 * Reprend les cartes `.gal-card` du module Galeries de l'accueil, avec la
 * couverture de chaque rubrique quand elle existe.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Category;
use App\Domain\Catalog\Media;
use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var list<Category> $rubriques */
$rubriques = $data['categories'];
/** @var array<int, Media> $couvertures */
$couvertures = $data['covers'];
?>
<section class="page-head wrap">
  <nav class="fil" aria-label="<?= $t('nav.breadcrumb') ?>">
    <ol>
      <li><a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>"><?= $t('nav.home') ?></a></li>
      <li><?= $t('nav.gallery') ?></li>
    </ol>
  </nav>

  <h1><?= $t('nav.gallery') ?></h1>
  <p class="intro"><?= $t('gallery.intro') ?></p>
</section>

<section class="galeries wrap" aria-label="<?= $t('nav.gallery') ?>">
  <?php if ($rubriques === []) : ?>
    <p class="vide"><?= $t('category.empty') ?></p>
  <?php else : ?>
  <div class="gal-grid<?php if (count($rubriques) > 2) : ?> auto<?php endif; ?>">
    <?php foreach ($rubriques as $rang => $rubrique) : ?>
    <a class="gal-card" href="<?= attr($url->route('category.show', ['locale' => $locale->value, 'slug' => $rubrique->slug($locale)->value])) ?>">
      <?php $couverture = $rubrique->coverMediaId === null ? null : ($couvertures[$rubrique->coverMediaId] ?? null); ?>
      <?php if ($couverture !== null) : ?>
        <?= $partial('partials/picture', [
            'media' => $couverture,
            'locale' => $locale,
            'sizes' => '(max-width: 900px) 100vw, 50vw',
            'priority' => $rang < 2,
            'label' => $rubrique->title($locale),
        ]) ?>
      <?php endif; ?>
      <?php if ($rubrique->eyebrow($locale) !== null) : ?>
      <p class="eyebrow"><?= e($rubrique->eyebrow($locale)) ?></p>
      <?php endif; ?>
      <h2><?= e($rubrique->title($locale)) ?></h2>
      <?php if ($rubrique->description($locale) !== null) : ?>
      <p><?= e(mb_substr(trim(strip_tags($rubrique->description($locale))), 0, 220)) ?></p>
      <?php endif; ?>
      <span class="lien"><?= $t('home.gallery_link', ['name' => mb_strtolower($rubrique->title($locale))]) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="cta-row cta-row--centre galerie-tout">
    <a class="btn btn-vide" href="<?= attr($url->route('artwork.index', ['locale' => $locale->value])) ?>"><?= $t('gallery.all_works') ?></a>
  </p>
</section>
