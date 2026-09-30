<?php

/**
 * Versions d'un élément (demande du 2026-09-30). Chaque ligne est l'état de
 * l'élément AVANT la modification indiquée ; « Restaurer » le remet en place.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<array{id: int, label: string, action: string, author: string|null, createdAt: string, bytes: int}> $versions */
$versions = is_array($data['versions'] ?? null) ? $data['versions'] : [];
$actions = [
    'update' => 'Modifié',
    'delete' => 'Supprimé',
    'restore' => 'Version restaurée',
];
$date = static fn (string $sql): string => (new DateTimeImmutable($sql))->format('d/m/Y à H:i:s');
?>
<div class="admin-page">
    <p class="fil-admin"><a href="<?= attr($base . '/admin/historique') ?>">← Historique</a></p>
    <h1><?= e($data['sujet'] ?? '') ?></h1>

    <?php if (($data['restaure'] ?? false) === true) : ?>
    <p class="succes" role="status">
        La version a été restaurée. L’état remplacé est gardé ci-dessous : vous pouvez revenir en arrière.
    </p>
    <?php endif; ?>

    <p class="aide">Chaque ligne est l’état de l’élément juste avant la modification indiquée.</p>

    <div class="tableau-defilant">
        <table class="tableau">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Ce qui s’est passé ensuite</th>
                    <th scope="col">Par</th>
                    <th scope="col"><span class="sr-only">Action</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($versions as $version) : ?>
                <tr>
                    <td><?= e($date($version['createdAt'])) ?></td>
                    <td><?= e($actions[$version['action']] ?? $version['action']) ?></td>
                    <td><?= e($version['author'] ?? '—') ?></td>
                    <td>
                        <form method="post" action="<?= attr($base . '/admin/historique/' . $version['id'] . '/restaurer') ?>">
                            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                            <button type="submit" class="bouton bouton--secondaire">Restaurer cette version</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
