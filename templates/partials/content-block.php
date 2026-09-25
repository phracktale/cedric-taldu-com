<?php

/**
 * Bloc de la bibliothèque placé dans une page — accueil ou template (retours
 * du 2026-09-25). Rien si le bloc a été supprimé depuis son placement.
 *
 * @var array<string, mixed> $data
 * @var callable             $partial
 */

declare(strict_types=1);

$place = is_array($data['placed'] ?? null) ? $data['placed'] : null;
?>
<?php if ($place !== null && $place['blocks'] !== []) : ?>
<div class="bloc-place">
  <?= $partial('partials/blocks', [
      'blocks' => $place['blocks'],
      'locale' => $data['locale'],
      'medias' => $place['medias'],
  ]) ?>
</div>
<?php endif; ?>
