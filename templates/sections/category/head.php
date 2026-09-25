<?php

/**
 * Section « Titre, introduction et séries » du modèle « Galerie » (retours du 2026-09-25).
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
<section class="page-head wrap">
  <nav class="fil" aria-label="<?= $t('nav.breadcrumb') ?>">
    <ol>
      <li><a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>"><?= $t('nav.home') ?></a></li>
      <li><a href="<?= attr($url->route('gallery.index', ['locale' => $locale->value])) ?>"><?= $t('nav.gallery') ?></a></li>
      <li><?= e($rubrique->title($locale)) ?></li>
    </ol>
  </nav>

  <?php if ($data['isTranslated'] === false) : ?>
    <?php /* 05-i18n-seo §3 : mention discrète, et hreflang non émis. */ ?>
    <p class="repli-langue">This text is only available in French.</p>
  <?php endif; ?>

  <?php if ($rubrique->eyebrow($locale) !== null) : ?>
  <p class="eyebrow"><?= e($rubrique->eyebrow($locale)) ?></p>
  <?php endif; ?>

  <h1><?= e($rubrique->title($locale)) ?></h1>

  <?php if ($rubrique->description($locale) !== null) : ?>
  <?php /* HTML assaini a l'ecriture par HtmlSanitizer : la lecture affiche. */ ?>
  <div class="intro"><?= richText($rubrique->description($locale)) ?></div>
  <?php endif; ?>

  <?php if ($series !== []) : ?>
  <nav class="series" aria-label="<?= $t('category.series') ?>">
    <a class="serie" href="<?= attr($lienRubrique(null)) ?>"<?php if ($serieChoisie === null) : ?> aria-current="page"<?php endif; ?>><?= $t('category.all') ?></a>
    <?php foreach ($series as $serie) : ?>
    <a class="serie" href="<?= attr($lienRubrique($serie)) ?>"<?php if ($serieChoisie?->id === $serie->id) : ?> aria-current="page"<?php endif; ?>><?= e($serie->title($locale)) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</section>
