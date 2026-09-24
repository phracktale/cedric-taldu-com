<?php

/**
 * Toutes les œuvres, galeries confondues (revue du 2026-09-24).
 *
 * Même grille et même pagination que la page rubrique (02-front-public §3).
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
/** @var list<Artwork> $oeuvres */
$oeuvres = $data['artworks'];
/** @var array<int, App\Domain\Catalog\Media> $medias */
$medias = $data['medias'];
$page = is_int($data['page']) ? $data['page'] : 1;
$pages = is_int($data['pages']) ? $data['pages'] : 1;
$lien = $url->route('artwork.index', ['locale' => $locale->value]);
?>
<section class="page-head wrap">
  <nav class="fil" aria-label="<?= $t('nav.breadcrumb') ?>">
    <ol>
      <li><a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>"><?= $t('nav.home') ?></a></li>
      <li><a href="<?= attr($url->route('gallery.index', ['locale' => $locale->value])) ?>"><?= $t('nav.gallery') ?></a></li>
      <li><?= $t('gallery.all_works_title') ?></li>
    </ol>
  </nav>

  <h1><?= $t('gallery.all_works_title') ?></h1>
</section>

<section class="grille wrap" aria-label="<?= $t('category.works') ?>">
  <?php if ($oeuvres === []) : ?>
    <p class="vide"><?= $t('category.empty') ?></p>
  <?php else : ?>
  <div class="oeuvres">
    <?php foreach ($oeuvres as $rang => $oeuvre) : ?>
      <?= $partial('partials/artwork-card', [
          'artwork' => $oeuvre,
          'locale' => $locale,
          'media' => $medias[$oeuvre->primaryMediaId] ?? null,
          'priority' => $rang < 2,
      ]) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($pages > 1) : ?>
  <nav class="pagination" aria-label="<?= $t('category.pagination') ?>">
    <?php for ($n = 1; $n <= $pages; $n++) : ?>
      <?php if ($n === $page) : ?>
        <span class="courante" aria-current="page"><?= e($n) ?></span>
      <?php else : ?>
        <a href="<?= attr($lien . ($n === 1 ? '' : '?page=' . $n)) ?>"><?= e($n) ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>
</section>
