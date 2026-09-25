<?php

/**
 * Section « Image de couverture » du modèle « Page » (retours du 2026-09-25).
 *
 * Reçoit les données de la page entière ; l'ordre et la présence des
 * sections viennent du modèle composé en back-office. Voir front/page.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Media;
use App\Domain\Editorial\Page;
use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var Page $page */
$page = $data['page'];
/** @var Media|null $cover image de couverture téléversée en back-office */
$cover = $data['cover'] ?? null;
?>
<?php if ($cover !== null) : ?>
  <div class="page-visuel">
    <?= $partial('partials/picture', [
        'media' => $cover,
        'locale' => $locale,
        'sizes' => '(max-width: 900px) 100vw, 72rem',
        'priority' => true,
        'label' => $page->title($locale),
    ]) ?>
  </div>
<?php endif; ?>
