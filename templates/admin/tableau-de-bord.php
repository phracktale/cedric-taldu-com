<?php

/**
 * Tableau de bord.
 *
 * Le lot 2 n'affiche QUE ce qu'il produit : l'etat du catalogue et les
 * dernieres actions tracees. Le chiffre d'affaires et les commandes en attente
 * de 04-back-office §2 arrivent au lot 3 — des compteurs a zero laisseraient
 * croire a une boutique deserte plutot qu'a une boutique non encore construite.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Admin\AdminUser;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$utilisateur = ($data['utilisateur'] ?? null) instanceof AdminUser ? $data['utilisateur'] : null;

/** @var array<string, int> $compteurs */
$compteurs = is_array($data['compteurs'] ?? null) ? $data['compteurs'] : [];

/** @var list<array<string, mixed>> $actions */
$actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];

?>
<div class="admin-page">
    <h1>Bonjour <?= e($utilisateur?->displayName ?? '') ?></h1>

    <section class="cartes" aria-label="État du catalogue">
        <article class="carte">
            <p class="carte-nombre"><?= e($compteurs['categories'] ?? 0) ?></p>
            <p class="carte-libelle">rubriques</p>
            <p class="carte-detail"><?= e($compteurs['categories_published'] ?? 0) ?> publiée(s)</p>
            <p><a href="<?= attr($base . '/admin/rubriques') ?>">Gérer les rubriques</a></p>
        </article>

        <article class="carte">
            <p class="carte-nombre"><?= e($compteurs['artworks'] ?? 0) ?></p>
            <p class="carte-libelle">œuvres</p>
            <p class="carte-detail">
                <?= e($compteurs['artworks_published'] ?? 0) ?> publiée(s),
                <?= e($compteurs['artworks_draft'] ?? 0) ?> en brouillon
            </p>
            <p><a href="<?= attr($base . '/admin/oeuvres') ?>">Gérer les œuvres</a></p>
        </article>

        <article class="carte">
            <p class="carte-nombre"><?= e($compteurs['media'] ?? 0) ?></p>
            <p class="carte-libelle">images</p>
            <p class="carte-detail"><?= e($compteurs['series'] ?? 0) ?> série(s)</p>
            <p><a href="<?= attr($base . '/admin/medias') ?>">Ouvrir la médiathèque</a></p>
        </article>
    </section>

    <section class="journal" aria-label="Dernières actions">
        <h2>Dernières actions</h2>

        <?php if ($actions === []) : ?>
        <p class="aide">Aucune action enregistrée pour l’instant.</p>
        <?php else : ?>
        <table class="tableau">
            <thead>
                <tr><th scope="col">Quand</th><th scope="col">Action</th><th scope="col">Sur</th></tr>
            </thead>
            <tbody>
            <?php foreach ($actions as $action) : ?>
                <tr>
                    <td><?= e($action['created_at'] ?? '') ?></td>
                    <td><?= e($action['action'] ?? '') ?></td>
                    <td><?= e($action['entity_type'] ?? '—') ?> <?= e($action['entity_id'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>
