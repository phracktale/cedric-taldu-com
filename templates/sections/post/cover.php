<?php

/**
 * Section « Image de couverture » du modèle « Actualité » (retours du 2026-09-25).
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
