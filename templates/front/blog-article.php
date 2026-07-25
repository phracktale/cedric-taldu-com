<?php

/**
 * Article d'actus (02-front §6).
 *
 * Le corps est du HTML DÉJÀ ASSAINI à l'écriture (06-securite §2) : il est
 * rendu par richText(), le seul helper autorisé pour du HTML de confiance.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Media;
use App\Domain\Editorial\Post;
use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var Post $post */
$post = $data['post'];
/** @var Media|null $cover */
$cover = $data['cover'] ?? null;
/** @var string $listUrl */
$listUrl = $data['listUrl'];

$dateAffichee = $post->eventDate ?? $post->publishedAt;
?>
<article class="wrap article">
  <nav class="fil" aria-label="<?= $t('nav.breadcrumb') ?>">
    <ol>
      <li><a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>"><?= $t('nav.home') ?></a></li>
      <li><a href="<?= attr($listUrl) ?>"><?= $t('nav.news') ?></a></li>
      <li><?= e($post->title($locale)) ?></li>
    </ol>
  </nav>

  <header class="article-tete">
    <?php if ($dateAffichee !== null) : ?>
      <p class="article-date"><?= e(dateLong($dateAffichee, $locale)) ?></p>
    <?php endif; ?>
    <h1><?= e($post->title($locale)) ?></h1>
    <?php if ($post->isEvent() && $post->eventPlace !== null) : ?>
      <p class="article-lieu"><?= e($post->eventPlace) ?></p>
    <?php endif; ?>
  </header>

  <?php if (!$post->isTranslatedIn($locale) && $locale !== Locale::Fr) : ?>
    <p class="article-langue" lang="en">This text is only available in French.</p>
  <?php endif; ?>

  <?php if ($cover !== null) : ?>
    <div class="article-visuel">
      <?= $partial('partials/picture', [
          'media' => $cover,
          'locale' => $locale,
          'sizes' => '(max-width: 900px) 100vw, 66vw',
          'priority' => true,
          'label' => $post->title($locale),
      ]) ?>
    </div>
  <?php endif; ?>

  <div class="article-corps">
    <?= richText($post->body($locale)) ?>
  </div>

  <p class="article-retour">
    <a href="<?= attr($listUrl) ?>"><?= $t('blog.back_to_list') ?></a>
  </p>
</article>
