<?php

/**
 * Accueil — ATELIER : portrait, bio courte, lien vers « À propos ».
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = $data['locale'];
$studio = $data['studio'];
/** @var list<string> $paragraphes */
$paragraphes = $data['paragraphes'];
/** @var callable $texte */
$texte = $data['texte'];
?>
<?php if ($texte($studio, 'title') !== null) : ?>
<section class="atelier wrap" id="atelier">
  <div class="atelier-grid">
    <?php if (($data['studioPortrait'] ?? null) instanceof App\Domain\Catalog\Media) : ?>
    <div class="portrait">
      <?= $partial('partials/picture', [
          'media' => $data['studioPortrait'],
          'locale' => $locale,
          'sizes' => '(max-width: 900px) 100vw, 40vw',
          'label' => $texte($studio, 'title') ?? '',
      ]) ?>
    </div>
    <?php else : ?>
    <div class="portrait"><span><?= $t('home.studio_portrait') ?></span></div>
    <?php endif; ?>
    <div>
      <?php if ($texte($studio, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($studio, 'eyebrow')) ?></p><?php endif; ?>
      <h2><?= e($texte($studio, 'title')) ?></h2>
      <?php if ($texte($studio, 'lead') !== null) : ?><p class="forte"><?= e($texte($studio, 'lead')) ?></p><?php endif; ?>
      <?php foreach ($paragraphes as $paragraphe) : ?>
        <?php if (is_string($paragraphe) && $paragraphe !== '') : ?><p><?= e($paragraphe) ?></p><?php endif; ?>
      <?php endforeach; ?>
      <?php if (isset($data['ctas']['atelier'])) : ?>
        <?= $partial('partials/cta', $data['ctas']['atelier']) ?>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
