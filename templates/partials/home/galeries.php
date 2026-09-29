<?php

/**
 * Accueil — GALERIES : une carte par rubrique publiée, alimentée par la base.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = $data['locale'];
/** @var list<App\Domain\Catalog\Category> $rubriques */
$rubriques = $data['rubriques'];
/** @var array<string, mixed> $titres réglage home.galleries (retour client du 2026-09-29) */
$titres = is_array($data['galleries'] ?? null) ? $data['galleries'] : [];
$saisi = static fn (string $cle): ?string
    => is_string($titres[$cle] ?? null) && trim($titres[$cle]) !== '' ? $titres[$cle] : null;
?>
<?php if ($rubriques !== []) : ?>
<section class="galeries wrap" id="galeries">
  <?php // Texte saisi en back-office, sinon le texte d'origine. ?>
  <p class="eyebrow"><?php if ($saisi('eyebrow') !== null) : ?><?= e($saisi('eyebrow')) ?><?php else : ?><?= $t('home.galleries_eyebrow') ?><?php endif; ?></p>
  <h2><?php if ($saisi('title') !== null) : ?><?= e($saisi('title')) ?><?php else : ?><?= $t('home.galleries_title') ?><?php endif; ?></h2>
  <?php if ($saisi('intro') !== null) : ?><p class="intro"><?= e($saisi('intro')) ?></p><?php endif; ?>
  <div class="gal-grid<?php if (count($rubriques) > 2) : ?> auto<?php endif; ?>">
    <?php foreach ($rubriques as $rubrique) : ?>
    <a class="gal-card" href="<?= attr($url->route('category.show', ['locale' => $locale->value, 'slug' => $rubrique->slug($locale)->value])) ?>">
      <h3><?= e($rubrique->title($locale)) ?></h3>
      <?php if ($rubrique->description($locale) !== null) : ?>
      <p><?= e(mb_substr(trim(strip_tags($rubrique->description($locale))), 0, 220)) ?></p>
      <?php endif; ?>
      <span class="lien"><?= $t('home.gallery_link', ['name' => mb_strtolower($rubrique->title($locale))]) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
