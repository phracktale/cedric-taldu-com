<?php

/**
 * Page d'accueil composée par glisser-déposer (retours du 2026-09-25).
 *
 * À gauche, les sections disponibles ; à droite, la page. On y glisse les
 * sections voulues, dans l'ordre voulu : c'est leur position qui décide. Chaque
 * section n'est utilisable qu'une fois ; son contenu s'édite par son lien.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Editorial\HomeLayout;
use App\Domain\Editorial\HomeSectionForm;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<array{section: string, enabled: bool, label: string}> $sections */
$sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
$page = array_values(array_filter($sections, static fn (array $s): bool => $s['enabled']));
$valeur = array_map(static fn (array $s): array => ['type' => $s['section']], $page);
$lien = static fn (string $section): ?string => HomeSectionForm::isEditable($section)
    ? $base . '/admin/accueil/' . $section
    : null;
?>
<div class="admin-page">
    <h1>Accueil</h1>

    <p class="aide">
        Faites glisser les sections disponibles dans la page, puis réordonnez-les en les
        déplaçant. Une section retirée de la page n’est plus affichée ; son contenu est conservé.
        Au clavier ou sur tablette, utilisez « Ajouter » et les flèches.
    </p>
    <noscript><p class="erreur">Le glisser-déposer demande JavaScript.</p></noscript>

    <form method="post" action="<?= attr($base) ?>/admin/accueil" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <div class="composer" data-composer>
            <aside class="composer-palette" aria-label="Sections disponibles">
                <h2>Sections disponibles</h2>
                <ul>
                    <?php foreach (HomeLayout::SECTIONS as $section => $libelle) : ?>
                    <?= $partial('admin/partials/composer-source', ['item' => ['type' => $section], 'label' => $libelle]) ?>
                    <?php endforeach; ?>
                </ul>
            </aside>

            <div class="composer-zones">
                <section class="composer-bloc">
                    <h2>La page d’accueil</h2>
                    <ol class="composer-zone" data-composer-zone data-name="sections" data-unique aria-label="Sections de la page d’accueil">
                        <?php foreach ($page as $section) : ?>
                        <?= $partial('admin/partials/composer-entree', [
                            'item' => ['type' => $section['section']],
                            'label' => $section['label'],
                            'editUrl' => $lien($section['section']),
                        ]) ?>
                        <?php endforeach; ?>
                    </ol>
                    <input type="hidden" name="sections" value="<?= attr(json_encode($valeur, JSON_THROW_ON_ERROR)) ?>">
                </section>
            </div>
        </div>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer la page</button>
        </p>
    </form>
</div>
