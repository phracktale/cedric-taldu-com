<?php

/**
 * Section « Grille des œuvres » du modèle « Galerie » (retours du 2026-09-25).
 *
 * Reçoit les données de la page entière ; l'ordre et la présence des
 * sections viennent du modèle composé en back-office. Voir front/category.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Series;
use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var Category $rubrique */
$rubrique = $data['category'];
/** @var list<Series> $series */
$series = $data['series'];
/** @var Series|null $serieChoisie */
$serieChoisie = $data['selectedSeries'];
/** @var list<Artwork> $oeuvres */
$oeuvres = $data['artworks'];
/** @var array<int, App\Domain\Catalog\Media> $medias */
$medias = $data['medias'];

$page = is_int($data['page']) ? $data['page'] : 1;
$pages = is_int($data['pages']) ? $data['pages'] : 1;

$lienRubrique = static fn (?Series $serie): string => $url->route('category.show', array_filter([
    'locale' => $locale->value,
    'slug' => $rubrique->slug($locale)->value,
    'serie' => $serie?->slug($locale)->value,
]));

?>
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
        <a href="<?= attr($lienRubrique($serieChoisie) . ($n === 1 ? '' : (str_contains($lienRubrique($serieChoisie), '?') ? '&' : '?') . 'page=' . $n)) ?>"><?= e($n) ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>
</section>
