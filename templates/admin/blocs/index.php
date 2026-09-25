<?php

/**
 * Contenus › Blocs : bibliothèque de blocs réutilisables (retours du
 * 2026-09-25). Un bloc créé ici se place par glisser-déposer dans l'accueil
 * ou dans un template (Paramètres › Templates).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Editorial\ContentBlock;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<ContentBlock> $blocs */
$blocs = is_array($data['blocs'] ?? null) ? $data['blocs'] : [];
/** @var array<string, array{label: string}> $modeles */
$modeles = is_array($data['modeles'] ?? null) ? $data['modeles'] : [];
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
?>
<div class="admin-page">
    <h1>Blocs</h1>

    <p class="aide">
        Des blocs à composer librement — bannière avec image, sections en colonnes, texte et
        image, appel à l’action… — puis à placer par glisser-déposer dans l’<a href="<?= attr($base . '/admin/accueil') ?>">accueil</a>
        ou dans un <a href="<?= attr($base . '/admin/templates') ?>">template</a>. Un même bloc peut servir à plusieurs endroits.
    </p>

    <form method="post" action="<?= attr($base . '/admin/blocs') ?>" class="formulaire formulaire-en-ligne">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
        <?php if ($erreur !== null) : ?>
        <p class="erreur" role="alert"><?= e($erreur) ?></p>
        <?php endif; ?>
        <p class="champ">
            <label for="nom">Nom du nouveau bloc</label>
            <input type="text" id="nom" name="nom" maxlength="120" required>
        </p>
        <p class="champ">
            <label for="modele">Partir de</label>
            <select id="modele" name="modele">
                <option value="">Un bloc vide</option>
                <?php foreach ($modeles as $cle => $modele) : ?>
                <option value="<?= attr($cle) ?>"><?= e($modele['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="actions"><button type="submit" class="bouton">Créer le bloc</button></p>
    </form>

    <?php if ($blocs === []) : ?>
    <p>Aucun bloc pour l’instant.</p>
    <?php else : ?>
    <table class="tableau">
        <thead>
            <tr>
                <th scope="col">Bloc</th>
                <th scope="col">Clef de placement</th>
                <th scope="col" class="colonne-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($blocs as $bloc) : ?>
            <tr>
                <td><a href="<?= attr($base . '/admin/blocs/' . $bloc->id) ?>"><?= e($bloc->name) ?></a></td>
                <td><code><?= e($bloc->key()) ?></code></td>
                <td class="colonne-actions">
                    <a href="<?= attr($base . '/admin/blocs/' . $bloc->id) ?>">Modifier</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
