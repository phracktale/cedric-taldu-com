<?php

/**
 * Extensions A-Press (lot 2) : liste, activation, désactivation.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use APress\Core\Extension\ExtensionInfo;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$message = is_string($data['message'] ?? null) ? $data['message'] : null;
/** @var list<ExtensionInfo> $extensions */
$extensions = is_array($data['extensions'] ?? null) ? $data['extensions'] : [];
?>
<div class="admin-page">
    <h1>Extensions</h1>

    <p class="aide">
        Une extension ajoute des écrans et des fonctions au site. La désactiver retire ses écrans
        mais conserve toutes ses données : la réactiver les retrouve telles quelles.
    </p>

    <?php if ($message !== null) : ?>
    <p class="message" role="status"><?= e($message) ?></p>
    <?php endif; ?>

    <?php if ($extensions === []) : ?>
    <p>Aucune extension n’est installée.</p>
    <?php else : ?>
    <table class="tableau">
        <thead>
            <tr>
                <th scope="col">Extension</th>
                <th scope="col">Version</th>
                <th scope="col">État</th>
                <th scope="col" class="colonne-actions">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($extensions as $extension) : ?>
            <tr>
                <td>
                    <strong><?= e($extension->manifest !== null ? $extension->manifest->label : $extension->name) ?></strong>
                    <?php if ($extension->manifest !== null && $extension->manifest->description !== '') : ?>
                    <br><span class="aide"><?= e($extension->manifest->description) ?></span>
                    <?php endif; ?>
                    <?php if ($extension->error !== null) : ?>
                    <br><span class="erreur"><?= e($extension->error) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e($extension->manifest !== null ? $extension->manifest->version : ($extension->state !== null ? $extension->state->version : '—')) ?></td>
                <td><?= e($extension->active ? 'Active' : 'Inactive') ?></td>
                <td class="colonne-actions">
                    <?php if ($extension->active) : ?>
                    <form method="post" action="<?= attr($base . '/admin/extensions/' . $extension->name . '/desactiver') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="bouton bouton-secondaire">Désactiver</button>
                    </form>
                    <?php elseif ($extension->manifest !== null && $extension->error === null) : ?>
                    <form method="post" action="<?= attr($base . '/admin/extensions/' . $extension->name . '/activer') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="bouton">Activer</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
