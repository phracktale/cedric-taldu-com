<?php

/**
 * Générateur du menu principal (revue du 2026-09-24).
 *
 * Position (1 = en premier), affichage et libellé facultatif par langue de
 * chaque rubrique fixe. Fonctionne sans JavaScript.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<array{item: string, enabled: bool, labels: array{fr: string, en: string}, label: string}> $entrees */
$entrees = is_array($data['entrees'] ?? null) ? $data['entrees'] : [];
?>
<div class="admin-page">
    <h1>Menu</h1>

    <p class="aide">
        Choisissez les rubriques du menu principal, leur ordre (1 = en premier) et, si
        vous le souhaitez, un libellé à vous — par exemple « Boutique » pour « Toutes les
        œuvres ». Un libellé vide garde le libellé par défaut.
    </p>

    <form method="post" action="<?= attr($base) ?>/admin/menu" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <table class="tableau">
            <thead>
                <tr>
                    <th scope="col">Rubrique</th>
                    <th scope="col">Position</th>
                    <th scope="col">Affichée</th>
                    <th scope="col">Libellé (français)</th>
                    <th scope="col">Libellé (anglais)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entrees as $rang => $entree) : ?>
                <?php $cle = $entree['item']; ?>
                <tr>
                    <td><?= e($entree['label']) ?></td>
                    <td>
                        <label class="visually-hidden" for="position_<?= attr($cle) ?>">Position de <?= e($entree['label']) ?></label>
                        <input type="number" id="position_<?= attr($cle) ?>" name="position_<?= attr($cle) ?>"
                               min="1" max="99" value="<?= attr($rang + 1) ?>" class="champ-court">
                    </td>
                    <td>
                        <label class="visually-hidden" for="affiche_<?= attr($cle) ?>">Afficher <?= e($entree['label']) ?></label>
                        <input type="checkbox" id="affiche_<?= attr($cle) ?>" name="affiche_<?= attr($cle) ?>" value="1"
                               <?php if ($entree['enabled']) : ?>checked<?php endif; ?>>
                    </td>
                    <?php foreach (['fr' => 'français', 'en' => 'anglais'] as $langue => $nom) : ?>
                    <td>
                        <label class="visually-hidden" for="libelle_<?= attr($cle . '_' . $langue) ?>">Libellé en <?= e($nom) ?></label>
                        <input type="text" id="libelle_<?= attr($cle . '_' . $langue) ?>"
                               name="libelle_<?= attr($cle . '_' . $langue) ?>" maxlength="60"
                               value="<?= attr($entree['labels'][$langue]) ?>">
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
        </p>
    </form>
</div>
