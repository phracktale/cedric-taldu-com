<?php

/**
 * Accueil — section HERO : le H1 SEO, la baseline, une porte d'entrée.
 *
 * Fond paramétrable (revue du 2026-09-24) : une vraie <picture> de la
 * médiathèque posée derrière le texte, et/ou une couleur servie par le <style>
 * à nonce de la mise en page (`--hero-fond`). Le ton règle la couleur du texte.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Media;

$locale = $data['locale'];
$hero = $data['hero'];
/** @var callable $texte */
$texte = $data['texte'];
/** @var array<string, array{cta: App\Domain\Editorial\Cta, href: string}> $ctas */
$ctas = $data['ctas'];
/** @var array{media?: Media|null, color?: string|null, tone?: string} $fond */
$fond = $data['heroBackground'];
$image = ($fond['media'] ?? null) instanceof Media ? $fond['media'] : null;
$habille = $image !== null || ($fond['color'] ?? null) !== null;
?>
<?php if ($habille) : ?>
<section class="hero hero--fond" data-ton="<?= attr($fond['tone'] ?? 'encre') ?>">
  <?php if ($image !== null) : ?>
  <div class="hero-fond" aria-hidden="true">
    <?= $partial('partials/picture', [
        'media' => $image,
        'locale' => $locale,
        'sizes' => '100vw',
        'priority' => true,
    ]) ?>
  </div>
  <?php endif; ?>
  <div class="wrap hero-contenu">
<?php else : ?>
<section class="hero wrap">
<?php endif; ?>
  <?php if ($texte($hero, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($hero, 'eyebrow')) ?></p><?php endif; ?>
  <h1><?= e($texte($hero, 'title') ?? 'Cédric Taldu') ?></h1>
  <?php if ($texte($hero, 'baseline') !== null) : ?><p class="baseline"><?= e($texte($hero, 'baseline')) ?></p><?php endif; ?>
  <?php if (isset($ctas['hero'])) : ?>
    <?= $partial('partials/cta', $ctas['hero']) ?>
  <?php endif; ?>
<?php if ($habille) : ?>
  </div>
<?php endif; ?>
</section>
