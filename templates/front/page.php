<?php

/**
 * Page éditoriale à code fixe (02-front §6).
 *
 * Le corps est du HTML DÉJÀ ASSAINI à l'écriture : rendu par richText(), le seul
 * helper autorisé pour du HTML de confiance.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Editorial\Page;
use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var Page $page */
$page = $data['page'];
?>
<article class="wrap page-editoriale">
  <header class="page-tete">
    <h1><?= e($page->title($locale)) ?></h1>
  </header>

  <?php if (!$page->isTranslatedIn($locale) && $locale !== Locale::Fr) : ?>
    <p class="page-langue" lang="en">This text is only available in French.</p>
  <?php endif; ?>

  <div class="page-corps">
    <?= richText($page->body($locale)) ?>
  </div>

  <?php // Version PDF téléchargeable des CGV (page à code fixe « terms »). ?>
  <?php if ($page->code === 'terms') : ?>
  <p class="page-pdf">
    <a class="btn btn-vide" target="_blank" rel="noopener"
       href="<?= attr($url->asset('documents/cgv-cedric-taldu-' . $locale->value . '.pdf')) ?>">
      <?= $t('page.download_pdf') ?>
    </a>
  </p>
  <?php endif; ?>
</article>
