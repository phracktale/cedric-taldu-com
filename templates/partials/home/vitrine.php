<?php

/**
 * Accueil — VITRINE : trois œuvres, celle du centre plus haute.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$locale = $data['locale'];
/** @var list<App\Domain\Catalog\Artwork> $vitrine */
$vitrine = $data['vitrine'];
/** @var array<int, App\Domain\Catalog\Media> $medias */
$medias = $data['medias'];
?>
<?php if ($vitrine !== []) : ?>
<section class="vitrine wrap" aria-label="<?= $t('home.showcase_label') ?>">
  <div class="vitrine-grid">
    <?php foreach ($vitrine as $rang => $oeuvre) : ?>
      <?= $partial('partials/artwork-card', [
          'artwork' => $oeuvre,
          'locale' => $locale,
          'media' => $medias[$oeuvre->primaryMediaId] ?? null,
          'class' => $rang === 1 ? 'large' : '',
          'priority' => $rang < 2,
      ]) ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
