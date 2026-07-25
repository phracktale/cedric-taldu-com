<?php

/**
 * Liste des actus (02-front §6).
 *
 * Chaque article : image à la une, date, titre, extrait, lieu d'événement le
 * cas échéant. Pagination par lien, fonctionnelle sans JavaScript.
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
/** @var list<Post> $posts */
$posts = $data['posts'];
/** @var array<int, Media> $medias */
$medias = is_array($data['medias'] ?? null) ? $data['medias'] : [];
/** @var callable(string): string $articleUrl */
$articleUrl = $data['articleUrl'];
$page = is_int($data['page'] ?? null) ? $data['page'] : 1;
$pages = is_int($data['pages'] ?? null) ? $data['pages'] : 1;
?>
<section class="page-head wrap">
  <p class="eyebrow"><?= $t('blog.journal') ?></p>
  <h1><?= $t('nav.news') ?></h1>
</section>

<div class="wrap actus">
  <?php if ($posts === []) : ?>
    <p class="actus-vide"><?= $t('blog.empty') ?></p>
  <?php else : ?>
    <ul class="actus-liste">
      <?php foreach ($posts as $post) : ?>
        <?php
        $lien = $articleUrl($post->slug($locale)->value);
        $couverture = $post->coverMediaId === null ? null : ($medias[$post->coverMediaId] ?? null);
        $dateAffichee = $post->eventDate ?? $post->publishedAt;
        ?>
        <li class="actu">
          <a class="actu-lien" href="<?= attr($lien) ?>">
            <div class="actu-visuel">
              <?= $partial('partials/picture', [
                  'media' => $couverture,
                  'locale' => $locale,
                  'sizes' => '(max-width: 760px) 100vw, 33vw',
                  'label' => $post->title($locale),
              ]) ?>
            </div>
            <div class="actu-texte">
              <?php if ($dateAffichee !== null) : ?>
                <p class="actu-date"><?= e(dateLong($dateAffichee, $locale)) ?></p>
              <?php endif; ?>
              <h2 class="actu-titre"><?= e($post->title($locale)) ?></h2>
              <?php if ($post->isEvent() && $post->eventPlace !== null) : ?>
                <p class="actu-lieu"><?= e($post->eventPlace) ?></p>
              <?php endif; ?>
              <?php if ($post->excerpt($locale) !== null) : ?>
                <p class="actu-extrait"><?= e((string) $post->excerpt($locale)) ?></p>
              <?php endif; ?>
            </div>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($pages > 1) : ?>
      <nav class="pagination" aria-label="<?= $t('blog.pages') ?>">
        <?php if ($page > 1) : ?>
          <a rel="prev" href="?page=<?= attr($page - 1) ?>"><?= $t('blog.previous') ?></a>
        <?php endif; ?>
        <span class="pagination-etat"><?= e($page) ?> / <?= e($pages) ?></span>
        <?php if ($page < $pages) : ?>
          <a rel="next" href="?page=<?= attr($page + 1) ?>"><?= $t('blog.next') ?></a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
