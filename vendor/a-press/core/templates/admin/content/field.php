<?php

/**
 * Un champ de l'éditeur, selon son type dans le modèle. Le nom HTML suit le
 * chemin de la donnée avec « -- » (FormDecoder) : seo--metaTitle,
 * missions--0--label.
 *
 * @var array<string, mixed>              $data  field, name, value, error
 * @var APress\Core\Ui\UrlBuilder          $url
 * @var class-string<APress\Core\Ui\Html> $h
 */

declare(strict_types=1);

use APress\Core\Content\FieldDefinition;

$champ = $data['field'];
assert($champ instanceof FieldDefinition);
$nom = (string) $data['name'];
$valeur = $data['value'] ?? null;
$erreur = is_string($data['error'] ?? null) ? $data['error'] : null;
$id = 'champ-' . str_replace('--', '-', $nom);
$texte = match (true) {
    is_scalar($valeur) => (string) $valeur,
    $valeur === null => '',
    $champ->type === 'lines' && is_array($valeur) => implode("\n", array_map(strval(...), array_filter($valeur, is_scalar(...)))),
    default => (string) json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
};
$dansListe = ($data['inList'] ?? false) === true;
$attributs = ($champ->required && !$dansListe ? ' required' : '') . ($champ->readonly ? ' readonly' : '')
    . ($erreur !== null ? ' aria-invalid="true" aria-describedby="' . $id . '-erreur"' : '');
?>
<div class="champ champ--<?= $h::attr($champ->type) ?>">
    <?php if ($champ->type === 'boolean') : ?>
    <label class="champ-case">
        <?php // Valeur VIDE, pas « 0 » : une ligne de liste non cochée doit rester une ligne vide (ignorée). ?>
        <input type="hidden" name="<?= $h::attr($nom) ?>" value="">
        <input type="checkbox" id="<?= $h::attr($id) ?>" name="<?= $h::attr($nom) ?>" value="1"<?= $valeur === true ? ' checked' : '' ?>>
        <?= $h::e($champ->label) ?>
    </label>
    <?php else : ?>
    <label for="<?= $h::attr($id) ?>"><?= $h::e($champ->label) ?><?= $h::e($champ->required ? ' *' : '') ?></label>
        <?php if (in_array($champ->type, ['text', 'json', 'lines'], true)) : ?>
    <textarea id="<?= $h::attr($id) ?>" name="<?= $h::attr($nom) ?>" rows="4"<?= $attributs ?>><?= $h::e($texte) ?></textarea>
        <?php elseif ($champ->type === 'richtext') : ?>
    <textarea id="<?= $h::attr($id) ?>" name="<?= $h::attr($nom) ?>" rows="14" class="texte-riche" data-richtext<?= $attributs ?>><?= $h::e($texte) ?></textarea>
        <?php elseif ($champ->type === 'enum') : ?>
    <select id="<?= $h::attr($id) ?>" name="<?= $h::attr($nom) ?>"<?= $attributs ?>>
        <option value="">—</option>
            <?php foreach ($champ->values as $option) : ?>
        <option value="<?= $h::attr($option) ?>"<?= $texte === $option ? ' selected' : '' ?>><?= $h::e($option) ?></option>
            <?php endforeach; ?>
    </select>
        <?php else : ?>
            <?php
            $html = match ($champ->type) {
                'email' => 'email',
                'url' => 'text',
                'integer', 'decimal' => 'number',
                'date' => 'date',
                'datetime' => 'datetime-local',
                default => 'text',
            };
            ?>
    <input type="<?= $h::attr($html) ?>" id="<?= $h::attr($id) ?>" name="<?= $h::attr($nom) ?>" value="<?= $h::attr($texte) ?>"<?= $champ->type === 'decimal' ? ' step="any"' : '' ?><?= $attributs ?>>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($champ->type === 'file' && $champ->download !== null && $texte !== '' && is_int($data['entryId'] ?? null)) : ?>
    <p class="description"><a href="<?= $h::attr($url->path(str_replace('{id}', (string) $data['entryId'], $champ->download))) ?>">Télécharger</a></p>
    <?php endif; ?>
    <?php if ($champ->help !== '') : ?>
    <p class="description"><?= $h::e($champ->help) ?></p>
    <?php endif; ?>
    <?php if ($champ->type === 'slug') : ?>
    <p class="description">Laissez vide pour la déduire du titre.</p>
    <?php endif; ?>
    <?php if ($erreur !== null) : ?>
    <p class="champ-erreur" id="<?= $h::attr($id) ?>-erreur"><?= $h::e($erreur) ?></p>
    <?php endif; ?>
</div>
