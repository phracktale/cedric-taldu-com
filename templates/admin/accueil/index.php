<?php

/**
 * Disposition de la page d'accueil (audit, P1 accueil).
 *
 * L'artiste règle l'ORDRE (par un numéro de position, 1 = en haut) et
 * l'ACTIVATION (case cochée) de chaque section. Fonctionne sans JavaScript ; le
 * contenu de chaque section s'édite ailleurs (réglages home.*).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<array{section: string, enabled: bool, label: string}> $sections */
$sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
?>
<div class="admin-page admin-page--etroite">
    <h1>Accueil</h1>

    <p class="aide">
        Réglez l’ordre et les sections affichées sur la page d’accueil. La position
        donne l’ordre (1 = tout en haut) ; décochez une section pour la masquer. Le
        contenu de chaque section se modifie dans ses réglages.
    </p>

    <form method="post" action="<?= attr($base) ?>/admin/accueil" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <table class="tableau">
            <thead>
                <tr>
                    <th scope="col">Section</th>
                    <th scope="col">Position</th>
                    <th scope="col" class="colonne-actions">Affichée</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sections as $rang => $section) : ?>
                <tr>
                    <td><?= e($section['label']) ?></td>
                    <td>
                        <label class="visually-hidden" for="position_<?= attr($section['section']) ?>">
                            Position de <?= e($section['label']) ?>
                        </label>
                        <input type="number" id="position_<?= attr($section['section']) ?>"
                               name="position_<?= attr($section['section']) ?>" min="1" max="99"
                               value="<?= attr($rang + 1) ?>" style="width: 4rem">
                    </td>
                    <td class="colonne-actions">
                        <label class="visually-hidden" for="affiche_<?= attr($section['section']) ?>">
                            Afficher <?= e($section['label']) ?>
                        </label>
                        <input type="checkbox" id="affiche_<?= attr($section['section']) ?>"
                               name="affiche_<?= attr($section['section']) ?>" value="1"
                               <?php if ($section['enabled']) : ?>checked<?php endif; ?>>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
        </p>
    </form>
</div>
