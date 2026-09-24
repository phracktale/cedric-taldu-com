<?php

/**
 * Choix d'une image : téléversement direct dans la médiathèque, ou numéro d'une
 * image existante (même mécanisme que les couvertures, voir CoverUpload).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$nom = is_string($data['nom'] ?? null) ? $data['nom'] : 'image';
$legende = is_string($data['legende'] ?? null) ? $data['legende'] : 'Image';
$valeur = is_string($data['valeur'] ?? null) ? $data['valeur'] : '';
$base = is_string($data['base'] ?? null) ? $data['base'] : '';
?>
<fieldset>
    <legend><?= e($legende) ?></legend>
    <p class="champ">
        <label for="<?= attr($nom) ?>_fichier">Téléverser une image</label>
        <input type="file" id="<?= attr($nom) ?>_fichier" name="<?= attr($nom) ?>_fichier"
               accept="image/jpeg,image/png,image/webp">
        <span class="champ-aide">JPEG, PNG ou WebP. L’image rejoint la médiathèque.</span>
    </p>
    <p class="champ">
        <label for="<?= attr($nom) ?>">ou numéro d’une image existante</label>
        <input type="number" id="<?= attr($nom) ?>" name="<?= attr($nom) ?>" min="1" value="<?= attr($valeur) ?>">
        <span class="champ-aide">
            Le numéro affiché dans la <a href="<?= attr($base . '/admin/medias') ?>">médiathèque</a>.
            Un fichier téléversé a la priorité.
        </span>
    </p>
    <?php if ($valeur !== '') : ?>
    <p class="champ champ-inline">
        <input type="checkbox" id="<?= attr($nom) ?>_retirer" name="<?= attr($nom) ?>_retirer" value="1">
        <label for="<?= attr($nom) ?>_retirer">Retirer l’image</label>
    </p>
    <?php endif; ?>
</fieldset>
