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
<article class="wrap page-editoriale">
  <header class="page-tete">
    <h1><?= e($page->title($locale)) ?></h1>
  </header>

  <?php if (!$page->isTranslatedIn($locale) && $locale !== Locale::Fr) : ?>
    <p class="page-langue" lang="en">This text is only available in French.</p>
  <?php endif; ?>

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

  <div class="page-corps">
    <?= richText($page->body($locale)) ?>
    <?php // Blocs (editor-core) APRÈS le corps : ils le complètent, ne le remplacent plus. ?>
    <?php if ($page->hasBlocks($locale)) : ?>
      <?= $partial('partials/blocks', [
          'blocks' => $page->blocks($locale),
          'locale' => $locale,
          'medias' => $data['blockMedias'] ?? [],
      ]) ?>
    <?php endif; ?>
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
