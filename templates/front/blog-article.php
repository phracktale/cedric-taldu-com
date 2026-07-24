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

$estFr = $locale === Locale::Fr;

$moisFr = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$moisEn = [1 => 'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'];

$formatDate = static function (?\DateTimeImmutable $date) use ($estFr, $moisFr, $moisEn): string {
    if ($date === null) {
        return '';
    }

    $mois = ($estFr ? $moisFr : $moisEn)[(int) $date->format('n')];

    return $estFr
        ? $date->format('j') . ' ' . $mois . ' ' . $date->format('Y')
        : $mois . ' ' . $date->format('j') . ', ' . $date->format('Y');
};

$dateAffichee = $post->eventDate ?? $post->publishedAt;
?>
<article class="wrap article">
  <nav class="fil" aria-label="<?= attr($estFr ? 'Fil d’Ariane' : 'Breadcrumb') ?>">
    <ol>
      <li><a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>"><?= e($estFr ? 'Accueil' : 'Home') ?></a></li>
      <li><a href="<?= attr($listUrl) ?>"><?= e($estFr ? 'Actus' : 'News') ?></a></li>
      <li><?= e($post->title($locale)) ?></li>
    </ol>
  </nav>

  <header class="article-tete">
    <?php if ($dateAffichee !== null) : ?>
      <p class="article-date"><?= e($formatDate($dateAffichee)) ?></p>
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
    <a href="<?= attr($listUrl) ?>"><?= e($estFr ? '← Toutes les actus' : '← All news') ?></a>
  </p>
</article>
