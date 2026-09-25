<?php

/**
 * Modèles de contenu composés par glisser-déposer (retours du 2026-09-25).
 *
 * Un compositeur par type : les sections disponibles à gauche, le modèle à
 * droite. Une section obligatoire (titre, contenu, formulaire…) ne peut pas
 * être retirée ; les autres se retirent et se replacent librement.
 *
 * @var array<string, mixed> $data
 * @var callable             $partial
 */

declare(strict_types=1);

use App\Domain\Editorial\ContentTemplate;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var array<string, list<string>> $modeles */
$modeles = is_array($data['modeles'] ?? null) ? $data['modeles'] : [];
?>
<div class="admin-page">
    <h1>Templates</h1>

    <p class="aide">
        Chaque type de contenu suit un modèle : faites glisser les sections disponibles dans le
        modèle, puis réordonnez-les. Les sections marquées « obligatoire » restent toujours
        présentes. Au clavier ou sur tablette, utilisez « Ajouter » et les flèches.
    </p>
    <noscript><p class="erreur">Le glisser-déposer demande JavaScript.</p></noscript>

    <form method="post" action="<?= attr($base) ?>/admin/templates" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <?php foreach (ContentTemplate::TYPES as $type => [$libelleType, $definition]) : ?>
            <?php $sections = $modeles[$type] ?? ContentTemplate::default($type)->sections(); ?>
        <fieldset class="composer-type">
            <legend><?= e($libelleType) ?></legend>
            <div class="composer" data-composer>
                <aside class="composer-palette" aria-label="<?= attr('Sections disponibles — ' . $libelleType) ?>">
                    <h2>Sections disponibles</h2>
                    <ul>
                        <?php foreach ($definition as $cle => [$libelle]) : ?>
                        <?= $partial('admin/partials/composer-source', ['item' => ['type' => $cle], 'label' => $libelle]) ?>
                        <?php endforeach; ?>
                    </ul>
                </aside>

                <div class="composer-zones">
                    <section class="composer-bloc">
                        <h2>Modèle « <?= e($libelleType) ?> »</h2>
                        <ol class="composer-zone" data-composer-zone data-name="<?= attr('template_' . $type) ?>" data-unique aria-label="<?= attr('Sections du modèle ' . $libelleType) ?>">
                            <?php foreach ($sections as $cle) : ?>
                            <?= $partial('admin/partials/composer-entree', [
                                'item' => ['type' => $cle],
                                'label' => $definition[$cle][0],
                                'required' => $definition[$cle][1],
                            ]) ?>
                            <?php endforeach; ?>
                        </ol>
                        <input type="hidden" name="<?= attr('template_' . $type) ?>" value="<?= attr(json_encode(array_map(static fn (string $cle): array => ['type' => $cle], $sections), JSON_THROW_ON_ERROR)) ?>">
                    </section>
                </div>
            </div>
        </fieldset>
        <?php endforeach; ?>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer les templates</button>
        </p>
    </form>
</div>
