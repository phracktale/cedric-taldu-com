<?php

/**
 * Changer son mot de passe (2026-10-19). Le mot de passe actuel est redemandé.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
$succes = is_string($data['succes'] ?? null) ? $data['succes'] : null;
$longueur = is_int($data['longueur'] ?? null) ? $data['longueur'] : 12;

?>
<div class="admin-page admin-page--etroite">
    <h1>Mot de passe</h1>

    <?php if ($succes !== null) : ?>
    <p class="succes" role="status"><?= e($succes) ?></p>
    <?php endif; ?>

    <?php if ($erreur !== null) : ?>
    <p class="erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>

    <p class="aide">
        Au moins <?= e($longueur) ?> caractères. Une phrase de quelques mots est plus
        facile à retenir, et plus solide, qu’un mot compliqué. Les autres sessions
        ouvertes sur ce compte seront fermées.
    </p>

    <form method="post" action="<?= attr($base . '/admin/compte/mot-de-passe') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="mot_de_passe_actuel">Mot de passe actuel</label>
            <input type="password" id="mot_de_passe_actuel" name="mot_de_passe_actuel"
                   autocomplete="current-password" required>
        </p>

        <p class="champ">
            <label for="nouveau">Nouveau mot de passe</label>
            <input type="password" id="nouveau" name="nouveau"
                   autocomplete="new-password" minlength="<?= attr($longueur) ?>" required>
        </p>

        <p class="champ">
            <label for="confirmation">Nouveau mot de passe, une seconde fois</label>
            <input type="password" id="confirmation" name="confirmation"
                   autocomplete="new-password" minlength="<?= attr($longueur) ?>" required>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Changer le mot de passe</button>
        </p>
    </form>

    <p class="aide"><a href="<?= attr($base . '/admin/compte/2fa') ?>">Double facteur</a></p>
</div>
