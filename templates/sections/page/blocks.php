<?php

/**
 * Section « Blocs » du modèle « Page » (retours du 2026-09-25).
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
<?php // Blocs (editor-core) : ils complètent le corps, ne le remplacent plus. ?>
<?php if ($page->hasBlocks($locale)) : ?>
<div class="page-corps page-corps--blocs">
  <?= $partial('partials/blocks', [
      'blocks' => $page->blocks($locale),
      'locale' => $locale,
      'medias' => $data['blockMedias'] ?? [],
  ]) ?>
</div>
<?php endif; ?>
