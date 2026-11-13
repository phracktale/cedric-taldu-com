<?php

/**
 * Thèmes A-Press (lot 4) : habillage du back-office.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use APress\Core\Theme\Theme;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$message = is_string($data['message'] ?? null) ? $data['message'] : null;
$actif = is_string($data['actif'] ?? null) ? $data['actif'] : 'classique';
/** @var list<Theme> $themes */
$themes = is_array($data['themes'] ?? null) ? $data['themes'] : [];
/** @var array<string, string> $erreurs */
$erreurs = is_array($data['erreurs'] ?? null) ? $data['erreurs'] : [];

$choix = [['nom' => 'classique', 'libelle' => 'Classique', 'description' => 'Mise en page d’origine du back-office.', 'version' => '']];
foreach ($themes as $theme) {
    $choix[] = ['nom' => $theme->name, 'libelle' => $theme->label, 'description' => $theme->description, 'version' => $theme->version];
}
?>
<div class="admin-page">
    <h1>Thèmes</h1>

    <p class="aide">
        Le thème habille le back-office. Il ne change ni le site public ni aucune donnée :
        on peut en changer à tout moment.
    </p>

    <?php if ($message !== null) : ?>
    <p class="message" role="status"><?= e($message) ?></p>
    <?php endif; ?>

    <table class="tableau">
        <thead>
            <tr>
                <th scope="col">Thème</th>
                <th scope="col">Version</th>
                <th scope="col">État</th>
                <th scope="col" class="colonne-actions">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($choix as $ligne) : ?>
            <tr>
                <td>
                    <strong><?= e($ligne['libelle']) ?></strong>
                    <?php if ($ligne['description'] !== '') : ?>
                    <br><span class="aide"><?= e($ligne['description']) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e($ligne['version'] !== '' ? $ligne['version'] : '—') ?></td>
                <td><?= e($ligne['nom'] === $actif ? 'Actif' : '') ?></td>
                <td class="colonne-actions">
                    <?php if ($ligne['nom'] !== $actif) : ?>
                    <form method="post" action="<?= attr($base . '/admin/themes/' . $ligne['nom'] . '/activer') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="bouton">Activer</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($erreurs !== []) : ?>
    <h2>Thèmes non proposés</h2>
    <ul>
        <?php foreach ($erreurs as $nom => $raison) : ?>
        <li><strong><?= e($nom) ?></strong> : <?= e($raison) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
