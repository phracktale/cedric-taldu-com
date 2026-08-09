<?php

/**
 * Accueil — TRIPTYQUE : les trois notions du livret.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$triptych = $data['triptych'];
/** @var list<array<string, mixed>> $cellules */
$cellules = $data['cellules'];
/** @var callable $texte */
$texte = $data['texte'];
?>
<?php if ($cellules !== []) : ?>
<section class="triptyque">
  <div class="wrap">
    <?php if ($texte($triptych, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($triptych, 'eyebrow')) ?></p><?php endif; ?>
    <h2><?= e($texte($triptych, 'title') ?? '') ?></h2>
    <?php if ($texte($triptych, 'intro') !== null) : ?><p class="intro"><?= e($texte($triptych, 'intro')) ?></p><?php endif; ?>
    <div class="tri-grid">
      <?php foreach ($cellules as $cellule) : ?>
      <div class="tri-cell">
        <h3><?= e($texte($cellule, 'title') ?? '') ?></h3>
        <p><?= e($texte($cellule, 'text') ?? '') ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
