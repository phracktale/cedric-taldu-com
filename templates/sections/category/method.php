<?php

/**
 * Section « Bande « méthode » » du modèle « Galerie » (retours du 2026-09-25).
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
<?php if ($rubrique->methodText($locale) !== null) : ?>
<?php /* 02-front-public §5 — bande « méthode », reprise de maquette/boutique-encres.html. */ ?>
<section class="methode">
  <div class="wrap">
    <?= richText($rubrique->methodText($locale)) ?>
    <hr class="stipple">
  </div>
</section>
<?php endif; ?>
