<?php

/**
 * Section « Titre » du modèle « Page » (retours du 2026-09-25).
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
<header class="page-tete">
  <h1><?= e($page->title($locale)) ?></h1>
</header>

<?php if (!$page->isTranslatedIn($locale) && $locale !== Locale::Fr) : ?>
  <p class="page-langue" lang="en">This text is only available in French.</p>
<?php endif; ?>
