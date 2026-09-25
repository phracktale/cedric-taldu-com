<?php

/**
 * Section « Lien PDF (CGV) » du modèle « Page » (retours du 2026-09-25).
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
<?php // Version PDF téléchargeable des CGV (page à code fixe « terms »). ?>
<?php if ($page->code === 'terms') : ?>
<p class="page-pdf">
  <a class="btn btn-vide" target="_blank" rel="noopener"
     href="<?= attr($url->asset('documents/cgv-cedric-taldu-' . $locale->value . '.pdf')) ?>">
    <?= $t('page.download_pdf') ?>
  </a>
</p>
<?php endif; ?>
