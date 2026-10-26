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
// Exposition : sa période (demande du 2026-09-30). La date de publication de
// l'article s'affiche à part, « Publié le » (retours Search Console du
// 2026-10-26) : les deux dates ne se confondent plus.
$periode = $post->eventDate === null ? null : datePeriode($post->eventDate, $post->eventEndDate, $locale);
$description = $post->isEvent() ? $post->eventDescription($locale) : null;
$annonce = $post->isEvent() ? $post->eventStatus->notice($locale) : null;
$adresse = $post->isEvent() ? $post->eventAddressLine() : null;
$organisateur = $post->isEvent() ? $post->eventOrganizer : null;
$entree = $post->isEvent() ? $post->eventPriceCents : null;
?>
<header class="article-tete">
  <?php if ($periode !== null) : ?>
    <p class="article-date"><?= e($periode) ?></p>
  <?php endif; ?>
  <?php if ($annonce !== null) : ?>
    <p class="article-statut"><?= e($annonce) ?></p>
  <?php endif; ?>
  <h1><?= e($post->title($locale)) ?></h1>
  <?php if ($post->isEvent() && $post->eventPlace !== null) : ?>
    <p class="article-lieu"><?= e($post->eventPlace) ?></p>
  <?php endif; ?>
  <?php if ($adresse !== null) : ?>
    <p class="article-adresse"><?= e($adresse) ?></p>
  <?php endif; ?>
  <?php if ($description !== null) : ?>
    <p class="article-expo-description"><?= e($description) ?></p>
  <?php endif; ?>
  <?php if ($organisateur !== null) : ?>
    <p class="article-organisateur"><?= $t('blog.organized_by') ?> <?php if ($post->eventOrganizerUrl !== null) : ?><a href="<?= attr($post->eventOrganizerUrl) ?>" rel="noopener noreferrer" target="_blank"><?= e($organisateur) ?></a><?php else : ?><?= e($organisateur) ?><?php endif; ?></p>
  <?php endif; ?>
  <?php if ($entree !== null) : ?>
    <?php if ($entree === 0) : ?>
    <p class="article-entree"><?= $t('blog.entry_free') ?></p>
    <?php else : ?>
    <p class="article-entree"><?= $t('blog.entry_price', ['price' => money(App\Domain\Money::fromCents($entree), $locale)]) ?></p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($post->isEvent() && $post->eventUrl !== null) : ?>
    <p class="article-expo-lien">
      <a href="<?= attr($post->eventUrl) ?>" rel="noopener noreferrer" target="_blank"><?= $t('blog.event_link') ?></a>
    </p>
  <?php endif; ?>
  <?php if ($post->publishedAt !== null) : ?>
    <p class="article-publie"><?= $t('blog.published_on', ['date' => dateLong($post->publishedAt, $locale)]) ?></p>
  <?php endif; ?>
</header>

<?php if (!$post->isTranslatedIn($locale) && $locale !== Locale::Fr) : ?>
  <p class="article-langue" lang="en">This text is only available in French.</p>
<?php endif; ?>
