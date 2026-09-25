<?php

/**
 * Page d'accueil composée par glisser-déposer (retours du 2026-09-25).
 *
 * À gauche, les sections du site, les blocs de la bibliothèque et les modèles
 * de bloc ; à droite, la page. On y glisse ce qu'on veut, dans l'ordre voulu :
 * c'est la position qui décide. Une section du site n'est utilisable qu'une
 * fois ; un « Nouveau bloc » est créé dans la bibliothèque à l'enregistrement,
 * puis s'édite (contenu et design) par son lien.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Editorial\HomeLayout;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<array{item: array<string, string>, label: string, editUrl: string|null}> $entrees */
$entrees = is_array($data['entrees'] ?? null) ? $data['entrees'] : [];
/** @var array<int, string> $bibliotheque */
$bibliotheque = is_array($data['bibliotheque'] ?? null) ? $data['bibliotheque'] : [];
/** @var array<string, array{label: string}> $modeles */
$modeles = is_array($data['modeles'] ?? null) ? $data['modeles'] : [];
$valeur = array_map(static fn (array $e): array => $e['item'], $entrees);
?>
<div class="admin-page">
    <h1>Accueil</h1>

    <p class="aide">
        Faites glisser les sections et les blocs dans la page, puis réordonnez-les en les
        déplaçant. Une section retirée de la page n’est plus affichée ; son contenu est conservé.
        Un « Nouveau bloc » (bannière, colonnes, texte + image…) est créé à l’enregistrement :
        son lien « Modifier le contenu » règle alors son texte, ses images et son design.
        Au clavier ou sur tablette, utilisez « Ajouter » et les flèches.
    </p>
    <noscript><p class="erreur">Le glisser-déposer demande JavaScript.</p></noscript>

    <form method="post" action="<?= attr($base) ?>/admin/accueil" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <div class="composer" data-composer>
            <aside class="composer-palette" aria-label="Éléments disponibles">
                <h2>Sections du site</h2>
                <ul>
                    <?php foreach (HomeLayout::SECTIONS as $section => $libelle) : ?>
                    <?= $partial('admin/partials/composer-source', ['item' => ['type' => $section], 'label' => $libelle]) ?>
                    <?php endforeach; ?>
                </ul>
                <?= $partial('admin/partials/composer-blocs', ['bibliotheque' => $bibliotheque, 'modeles' => $modeles, 'basePath' => $base]) ?>
            </aside>

            <div class="composer-zones">
                <section class="composer-bloc">
                    <h2>La page d’accueil</h2>
                    <ol class="composer-zone" data-composer-zone data-name="sections" data-unique aria-label="Sections de la page d’accueil">
                        <?php foreach ($entrees as $entree) : ?>
                        <?= $partial('admin/partials/composer-entree', $entree) ?>
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
