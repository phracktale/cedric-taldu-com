<?php

/**
 * Liste des articles du blog (04-back-office §9).
 *
 * Publier / dépublier / supprimer sont des POST : 06-securite §3 interdit toute
 * action modifiante par simple lien GET, et 04-back-office §12 exige que tout
 * fonctionne sans JavaScript.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var list<array<string, mixed>> $articles */
$articles = is_array($data['articles'] ?? null) ? $data['articles'] : [];

?>
<div class="admin-page">
    <h1>Actus</h1>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <p class="actions">
        <a class="bouton" href="<?= attr($base . '/admin/actus/nouvel-article') ?>">Nouvel article</a>
    </p>

    <?php if ($articles === []) : ?>
    <p class="aide">Aucun article pour le moment.</p>
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
        <?php foreach ($articles as $article) : ?>
            <?php
            $fr = is_array($article['translations']['fr'] ?? null) ? $article['translations']['fr'] : [];
            $publie = ($article['is_published'] ?? false) === true;
            ?>
            <tr>
                <td>
                    <a href="<?= attr($base . '/admin/actus/' . $article['id']) ?>"><?= e($fr['title'] ?? 'Sans titre') ?></a>
                </td>
                <td><code><?= e($fr['slug'] ?? '') ?></code></td>
                <td>
                    <?php if ($publie) : ?>
                    <span class="pastille pastille--publie">Publié</span>
                    <?php else : ?>
                    <span class="pastille pastille--brouillon">Brouillon</span>
                    <?php endif; ?>
                </td>
                <td class="colonne-actions">
                    <form method="post" action="<?= attr($base . '/admin/actus/' . $article['id'] . '/publication') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="lien-bouton">
                            <?php if ($publie) : ?>Dépublier<?php else : ?>Publier<?php endif; ?>
                        </button>
                    </form>

                    <form method="post" action="<?= attr($base . '/admin/actus/' . $article['id'] . '/suppression') ?>"
                          data-confirmation="Supprimer définitivement cet article ?">
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
