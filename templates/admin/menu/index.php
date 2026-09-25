<?php

/**
 * Menus du site par glisser-déposer (retours du 2026-09-25) : palette à gauche,
 * menu principal et menu du pied de page à droite. L'ordre est la position.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Editorial\NavMenu;
use App\Http\Controller\Admin\MenuController;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var array<int, string> $galeries */
$galeries = is_array($data['galeries'] ?? null) ? $data['galeries'] : [];
/** @var array<string, NavMenu> $menus */
$menus = ['menu_principal' => $data['principal'], 'menu_pied' => $data['pied']];
$titres = ['menu_principal' => 'Menu principal', 'menu_pied' => 'Menu du pied de page'];

?>
<div class="admin-page">
    <h1>Menu</h1>

    <p class="aide">
        Faites glisser les éléments disponibles dans le menu principal ou le menu du pied de
        page, puis réordonnez-les en les déplaçant : c’est leur position qui décide de l’ordre.
        Au clavier ou sur tablette, utilisez « Ajouter » et les flèches.
    </p>
    <noscript><p class="erreur">Le glisser-déposer demande JavaScript.</p></noscript>

    <form method="post" action="<?= attr($base) ?>/admin/menu" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <div class="composer" data-composer>
            <aside class="composer-palette" aria-label="Éléments disponibles">
                <h2>Éléments disponibles</h2>
                <p class="champ">
                    <label for="cible">Ajouter au</label>
                    <select id="cible" data-composer-target>
                        <?php foreach ($titres as $nom => $titre) : ?>
                        <option value="<?= attr($nom) ?>"><?= e($titre) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <h3>Rubriques du site</h3>
                <ul>
                    <?php foreach (NavMenu::SECTIONS as $type => $libelle) : ?>
                    <?= $partial('admin/partials/composer-source', ['item' => ['type' => $type, 'labels' => ['fr' => '', 'en' => '']], 'label' => $libelle]) ?>
                    <?php endforeach; ?>
                </ul>

                <h3>Pages</h3>
                <ul>
                    <?php foreach (NavMenu::PAGES as $code => $libelle) : ?>
                    <?= $partial('admin/partials/composer-source', ['item' => ['type' => 'page', 'ref' => $code, 'labels' => ['fr' => '', 'en' => '']], 'label' => $libelle]) ?>
                    <?php endforeach; ?>
                </ul>

                <h3>Galeries</h3>
                <ul>
                    <?php foreach ($galeries as $id => $titre) : ?>
                    <?= $partial('admin/partials/composer-source', ['item' => ['type' => 'category', 'ref' => (string) $id, 'labels' => ['fr' => '', 'en' => '']], 'label' => $titre]) ?>
                    <?php endforeach; ?>
                </ul>

                <h3>Lien direct</h3>
                <div class="composer-lien" data-composer-link>
                    <label>Libellé (français) <input type="text" name="lien_fr" maxlength="60"></label>
                    <label>Libellé (anglais) <input type="text" name="lien_en" maxlength="60"></label>
                    <label>Adresse <input type="text" name="lien_url" maxlength="500" placeholder="/fr/livret ou https://…"></label>
                    <p class="erreur" data-composer-link-error hidden>Un libellé et une adresse « /… » ou « https://… » sont nécessaires.</p>
                    <button type="button" class="eb-btn">+ Ajouter le lien</button>
                </div>
            </aside>

            <div class="composer-zones">
                <?php foreach ($menus as $nom => $menu) : ?>
                <section class="composer-bloc">
                    <h2><?= e($titres[$nom]) ?></h2>
                    <ol class="composer-zone" data-composer-zone data-name="<?= attr($nom) ?>" data-labels
                        aria-label="<?= attr($titres[$nom]) ?>">
                        <?php foreach ($menu->toArray() as $item) : ?>
                        <?= $partial('admin/partials/composer-entree', [
                            'item' => $item,
                            'label' => MenuController::adminLabel($item, $galeries),
                            'labels' => true,
                        ]) ?>
                        <?php endforeach; ?>
                    </ol>
                    <input type="hidden" name="<?= attr($nom) ?>" value="<?= attr(json_encode($menu->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>">
                </section>
                <?php endforeach; ?>
            </div>
        </div>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer les menus</button>
        </p>
    </form>
</div>
