<?php

/**
 * Élément disponible de la palette du compositeur (retours du 2026-09-25) :
 * à glisser dans une zone, ou à ajouter par son bouton.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

/** @var array<string, mixed> $item */
$item = is_array($data['item'] ?? null) ? $data['item'] : [];
$label = is_string($data['label'] ?? null) ? $data['label'] : '';
?>
<li class="composer-source" data-composer-source draggable="true" data-item="<?= jsonAttr($item) ?>" data-label="<?= attr($label) ?>">
    <span><?= e($label) ?></span>
    <button type="button" class="eb-btn" aria-label="Ajouter « <?= attr($label) ?> »">+ Ajouter</button>
</li>
