<?php

/**
 * Section « Fil d’Ariane » du modèle « Œuvre » (retours du 2026-09-25).
 *
 * Reçoit les données de la page entière ; l'ordre et la présence des
 * sections viennent du modèle composé en back-office. Voir front/artwork.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Media;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Shop\ProductKind;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var Artwork $oeuvre */
$oeuvre = $data['artwork'];
/** @var Category|null $rubrique */
$rubrique = $data['category'];
/** @var list<Artwork> $liees */
$liees = $data['related'];
/** @var array<int, Media> $medias */
$medias = $data['medias'];
/** @var list<App\Domain\Shop\Product> $products */
$products = $data['products'];
/** @var string $cartAddUrl */
$cartAddUrl = $data['cartAddUrl'];
/** @var string $csrfToken */
$csrfToken = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

$media = $medias[$oeuvre->primaryMediaId] ?? null;

?>
<div class="wrap">
  <nav class="fil" aria-label="<?= $t('nav.breadcrumb') ?>">
    <ol>
      <li><a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>"><?= $t('nav.home') ?></a></li>
      <li><a href="<?= attr($url->route('gallery.index', ['locale' => $locale->value])) ?>"><?= $t('nav.gallery') ?></a></li>
      <?php if ($rubrique !== null) : ?>
      <li><a href="<?= attr($url->route('category.show', ['locale' => $locale->value, 'slug' => $rubrique->slug($locale)->value])) ?>"><?= e($rubrique->title($locale)) ?></a></li>
      <?php endif; ?>
      <li><?= e($oeuvre->title($locale)) ?></li>
    </ol>
  </nav>
</div>
