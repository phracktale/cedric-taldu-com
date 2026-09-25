<?php

/**
 * Entrée d'une zone du compositeur (retours du 2026-09-25). Même balisage que
 * celui créé par composer.js pour une entrée ajoutée.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

/** @var array<string, mixed> $item */
$item = is_array($data['item'] ?? null) ? $data['item'] : [];
$label = is_string($data['label'] ?? null) ? $data['label'] : '';
$libelles = ($data['labels'] ?? false) === true;
$lienEdition = is_string($data['editUrl'] ?? null) ? $data['editUrl'] : null;
?>
<li class="composer-entree" data-composer-entry data-item="<?= jsonAttr($item) ?>">
    <span class="composer-poignee" aria-hidden="true">⠿</span>
    <span class="composer-nom"><?= e($label) ?></span>
    <?php if ($libelles) : ?>
    <details class="composer-libelles">
        <summary>Libellés</summary>
        <label>Français <input type="text" maxlength="60" data-composer-label="fr" value="<?= attr($item['labels']['fr'] ?? '') ?>" placeholder="<?= attr($label) ?>"></label>
        <label>Anglais <input type="text" maxlength="60" data-composer-label="en" value="<?= attr($item['labels']['en'] ?? '') ?>"></label>
    </details>
    <?php endif; ?>
    <?php if ($lienEdition !== null) : ?>
    <a class="composer-editer" href="<?= attr($lienEdition) ?>">Modifier le contenu</a>
    <?php endif; ?>
    <span class="composer-actions">
        <button type="button" class="eb-btn" data-composer-up aria-label="Monter « <?= attr($label) ?> »">↑</button>
        <button type="button" class="eb-btn" data-composer-down aria-label="Descendre « <?= attr($label) ?> »">↓</button>
        <button type="button" class="eb-btn" data-composer-remove aria-label="Retirer « <?= attr($label) ?> »">✕</button>
    </span>
</li>
