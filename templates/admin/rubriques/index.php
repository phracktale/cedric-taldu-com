<?php

/**
 * Liste des rubriques.
 *
 * Le deplacement d'un cran se fait par deux formulaires POST : 06-securite §3
 * interdit les actions modifiantes par simple lien GET, et 04-back-office §12
 * exige que l'operation fonctionne sans JavaScript. Le glisser-deposer viendra
 * par-dessus, sans remplacer ceci.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var list<array<string, mixed>> $rubriques */
$rubriques = is_array($data['rubriques'] ?? null) ? $data['rubriques'] : [];

?>
<div class="admin-page">
    <h1>Rubriques</h1>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <p class="actions">
        <a class="bouton" href="<?= attr($base . '/admin/rubriques/nouvelle') ?>">Nouvelle rubrique</a>
    </p>

    <?php if ($rubriques === []) : ?>
    <p class="aide">Aucune rubrique. La galerie du site est vide tant qu’il n’y en a pas.</p>
    <?php else : ?>
    <table class="tableau">
        <thead>
            <tr>
                <th scope="col">Titre</th>
                <th scope="col">Identifiant d’URL</th>
                <th scope="col">État</th>
                <th scope="col" class="colonne-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rubriques as $rubrique) : ?>
            <?php $fr = $rubrique['translations']['fr'] ?? []; ?>
            <tr>
                <td>
                    <a href="<?= attr($base . '/admin/rubriques/' . $rubrique['id']) ?>"><?= e($fr['title'] ?? 'Sans titre') ?></a>
                </td>
                <td><code><?= e($fr['slug'] ?? '') ?></code></td>
                <td>
                    <?php if ($rubrique['is_published'] === true) : ?>
                    <span class="pastille pastille--publie">Publiée</span>
                    <?php else : ?>
                    <span class="pastille pastille--brouillon">Non publiée</span>
                    <?php endif; ?>
                </td>
                <td class="colonne-actions">
                    <form method="post" action="<?= attr($base . '/admin/rubriques/' . $rubrique['id'] . '/position') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" name="direction" value="haut" class="lien-bouton"
                                aria-label="Monter cette rubrique">↑</button>
                        <button type="submit" name="direction" value="bas" class="lien-bouton"
                                aria-label="Descendre cette rubrique">↓</button>
                    </form>

                    <form method="post" action="<?= attr($base . '/admin/rubriques/' . $rubrique['id'] . '/publication') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="lien-bouton">
                            <?php if ($rubrique['is_published'] === true) : ?>Dépublier<?php else : ?>Publier<?php endif; ?>
                        </button>
                    </form>

                    <form method="post" action="<?= attr($base . '/admin/rubriques/' . $rubrique['id'] . '/suppression') ?>"
                          data-confirmation="Supprimer définitivement cette rubrique ?">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="lien-bouton">Supprimer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
