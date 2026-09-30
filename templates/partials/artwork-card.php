<?php

/**
 * Vignette d'œuvre : cadre blanc, visuel, légende.
 *
 * Reprend exactement la structure des maquettes — `.oeuvre > .cadre > .dessin`
 * puis `.legende` avec le titre en `<strong>`, la technique et les dimensions,
 * et le marqueur « Disponible en boutique » quand il informe.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Artwork;
use App\Domain\Locale;

/** @var Artwork $artwork */
$artwork = $data['artwork'];
/** @var Locale $locale */
$locale = $data['locale'];
$media = $data['media'] ?? null;
$classe = is_string($data['class'] ?? null) ? $data['class'] : '';
$prioritaire = ($data['priority'] ?? false) === true;
// Vignettes nettes (2026-09-30) : grille, liees, vitrine ou vitrine-large.
$contexte = is_string($data['context'] ?? null) ? $data['context'] : 'grille';

$caracteristiques = array_filter([
    $artwork->technique,
    $artwork->dimensions?->format($locale),
]);

?>
<a class="oeuvre <?= attr($classe) ?>" href="<?= attr($url->route('artwork.show', ['locale' => $locale->value, 'slug' => $artwork->slug($locale)->value])) ?>">
  <div class="cadre"><?= $partial('partials/picture', [
      'media' => $media,
      'locale' => $locale,
      'label' => $artwork->title($locale),
      'priority' => $prioritaire,
      'thumbnail' => $contexte,
  ]) ?></div>
  <p class="legende">
    <strong><?= e($artwork->caption($locale)) ?></strong>
    <?= e(implode(', ', $caracteristiques)) ?>
    <?php if ($artwork->isPurchasable()) : ?>
      <br><span class="dispo"><?= $t('artwork.original_available') ?></span>
    <?php endif; ?>
  </p>
</a>
