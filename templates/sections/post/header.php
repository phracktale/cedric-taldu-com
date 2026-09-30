<?php

/**
 * Section « Date et titre » du modèle « Actualité » (retours du 2026-09-25).
 *
 * Reçoit les données de la page entière ; l'ordre et la présence des
 * sections viennent du modèle composé en back-office. Voir front/blog-article.
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
<?php
// Exposition : sa période (demande du 2026-09-30) ; sinon la date de publication.
$dateTexte = $post->eventDate === null ? dateLong($dateAffichee, $locale)
    : datePeriode($post->eventDate, $post->eventEndDate, $locale);
$description = $post->isEvent() ? $post->eventDescription($locale) : null;
?>
<header class="article-tete">
  <?php if ($dateAffichee !== null) : ?>
    <p class="article-date"><?= e($dateTexte) ?></p>
  <?php endif; ?>
  <h1><?= e($post->title($locale)) ?></h1>
  <?php if ($post->isEvent() && $post->eventPlace !== null) : ?>
    <p class="article-lieu"><?= e($post->eventPlace) ?></p>
  <?php endif; ?>
  <?php if ($post->isEvent() && $post->eventAddress !== null) : ?>
    <p class="article-adresse"><?= e($post->eventAddress) ?></p>
  <?php endif; ?>
  <?php if ($post->isEvent() && $description !== null) : ?>
    <p class="article-expo-description"><?= e($description) ?></p>
  <?php endif; ?>
  <?php if ($post->isEvent() && $post->eventUrl !== null) : ?>
    <p class="article-expo-lien">
      <a href="<?= attr($post->eventUrl) ?>" rel="noopener noreferrer" target="_blank"><?= $t('blog.event_link') ?></a>
    </p>
  <?php endif; ?>
</header>

<?php if (!$post->isTranslatedIn($locale) && $locale !== Locale::Fr) : ?>
  <p class="article-langue" lang="en">This text is only available in French.</p>
<?php endif; ?>
