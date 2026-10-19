<?php

/**
 * Choisir un nouveau mot de passe depuis le lien reçu par e-mail (2026-10-19).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
$valable = ($data['valable'] ?? false) === true;
$action = is_string($data['action'] ?? null) ? $data['action'] : '';
$longueur = is_int($data['longueur'] ?? null) ? $data['longueur'] : 12;

?>
<div class="admin-connexion">
    <?php if (!$valable) : ?>
    <h1>Lien expiré</h1>

    <p class="erreur" role="alert">
        Ce lien n’est plus valable : il a déjà servi, ou il a expiré.
    </p>
    <p class="aide"><a href="<?= attr($base . '/admin/mot-de-passe-oublie') ?>">Demander un nouveau lien</a></p>
    <?php else : ?>
    <h1>Nouveau mot de passe</h1>

    <?php if ($erreur !== null) : ?>
    <p class="erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>

    <p class="aide">
        Au moins <?= e($longueur) ?> caractères. Une phrase de quelques mots est plus
        facile à retenir, et plus solide, qu’un mot compliqué.
    </p>

    <form method="post" action="<?= attr($action) ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="nouveau">Nouveau mot de passe</label>
            <input type="password" id="nouveau" name="nouveau"
                   autocomplete="new-password" minlength="<?= attr($longueur) ?>" required autofocus>
        </p>

        <p class="champ">
            <label for="confirmation">Nouveau mot de passe, une seconde fois</label>
            <input type="password" id="confirmation" name="confirmation"
                   autocomplete="new-password" minlength="<?= attr($longueur) ?>" required>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer le mot de passe</button>
        </p>
    </form>
    <?php endif; ?>
</div>
