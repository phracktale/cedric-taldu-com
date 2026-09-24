<?php

/**
 * Apparence du site (revue du 2026-09-24) : entrée de menu active et bouton de
 * fin d'actualité.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var array<string, string> $styles */
$styles = is_array($data['styles'] ?? null) ? $data['styles'] : [];
$style = is_string($data['style'] ?? null) ? $data['style'] : '';
$couleur = is_string($data['couleur'] ?? null) ? $data['couleur'] : '';
?>
<div class="admin-page admin-page--etroite">
    <h1>Apparence</h1>

    <form method="post" action="<?= attr($base . '/admin/apparence') ?>" class="formulaire" data-surveiller>
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <fieldset>
            <legend>Entrée de menu active</legend>
            <p class="champ-aide">Comment signaler, dans le menu, la rubrique où se trouve le visiteur.</p>
            <?php foreach ($styles as $valeur => $libelle) : ?>
            <p class="champ champ-inline">
                <input type="radio" id="style_<?= attr($valeur) ?>" name="style" value="<?= attr($valeur) ?>"
                    <?php if ($style === $valeur) : ?>checked<?php endif; ?>>
                <label for="style_<?= attr($valeur) ?>"><?= e($libelle) ?></label>
            </p>
            <?php endforeach; ?>
            <p class="champ">
                <label for="couleur">Couleur (style « Couleur »)</label>
                <input type="text" id="couleur" name="couleur" maxlength="7" pattern="#[0-9a-fA-F]{6}"
                       placeholder="#8c5a2b" value="<?= attr($couleur) ?>">
                <span class="champ-aide">Format #rrggbb. Vide : teinte par défaut.</span>
            </p>
        </fieldset>

        <?= $partial('admin/partials/cta-champs', [
            'valeurs' => $data['valeurs'] ?? [],
            'rubriques' => $data['rubriques'] ?? [],
            'legende' => 'Bouton en fin d’actualité',
            'aide' => 'Affiché sous chaque article, par exemple pour inviter à voir les œuvres.',
        ]) ?>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
        </p>
    </form>
</div>
