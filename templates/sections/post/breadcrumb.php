<?php

/**
 * Section « Fil d’Ariane » du modèle « Actualité » (retours du 2026-09-25).
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
<nav class="fil" aria-label="<?= $t('nav.breadcrumb') ?>">
  <ol>
    <li><a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>"><?= $t('nav.home') ?></a></li>
    <li><a href="<?= attr($listUrl) ?>"><?= $t('nav.news') ?></a></li>
    <li><?= e($post->title($locale)) ?></li>
  </ol>
</nav>
