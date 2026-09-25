<?php

/**
 * Image de fond d'une bannière ou d'une section (retours du 2026-09-25).
 *
 * La CSP interdit l'attribut style : pas de background-image en ligne. Le fond
 * est une <picture> ordinaire, placée sous le contenu et étirée par la CSS
 * (.bloc-fond, object-fit: cover) — elle garde ses dérivés responsives.
 *
 * @var array<string, mixed> $data
 * @var callable             $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Media;

$media = $data['media'] ?? null;
?>
<?php if ($media instanceof Media) : ?>
<div class="bloc-fond">
  <?= $partial('partials/picture', [
      'media' => $media,
      'locale' => $data['locale'],
      'sizes' => '100vw',
      'label' => is_string($data['label'] ?? null) ? $data['label'] : '',
  ]) ?>
</div>
<?php endif; ?>
