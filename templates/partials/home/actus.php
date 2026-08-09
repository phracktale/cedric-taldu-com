<?php

/**
 * Accueil — ACTUS : les trois derniers articles publiés.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = $data['locale'];
$news = $data['news'];
/** @var list<App\Domain\Editorial\Post> $recentPosts */
$recentPosts = $data['recentPosts'];
/** @var callable(App\Domain\Editorial\Post): string $articleUrl */
$articleUrl = $data['articleUrl'];
$newsIndexUrl = is_string($data['newsIndexUrl'] ?? null) ? $data['newsIndexUrl'] : '';
/** @var callable $texte */
$texte = $data['texte'];
?>
<?php if ($recentPosts !== []) : ?>
<section class="actus" id="actus">
  <div class="wrap">
    <p class="eyebrow"><?= $t('home.news_eyebrow') ?></p>
    <h2><?php if ($texte($news, 'title') !== null) : ?><?= e($texte($news, 'title')) ?><?php else : ?><?= $t('home.news_title') ?><?php endif; ?></h2>
    <div class="actu-liste">
      <?php foreach ($recentPosts as $post) : ?>
        <?php $dateAffichee = $post->eventDate ?? $post->publishedAt; ?>
      <article class="actu">
        <?php if ($dateAffichee !== null) : ?>
        <time datetime="<?= attr($dateAffichee->format('Y-m-d')) ?>"><?= e(dateLong($dateAffichee, $locale)) ?></time>
        <?php endif; ?>
        <h3><a href="<?= attr($articleUrl($post)) ?>"><?= e($post->title($locale)) ?></a></h3>
        <?php if ($post->isEvent() && $post->eventPlace !== null) : ?>
        <p class="lieu"><?= e($post->eventPlace) ?></p>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="actus-plus">
      <a href="<?= attr($newsIndexUrl) ?>"><?= $t('home.all_news') ?></a>
    </p>
  </div>
</section>
<?php endif; ?>
