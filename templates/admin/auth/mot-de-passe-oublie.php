<?php

/**
 * Mot de passe oublié : demande d'un lien (2026-10-19).
 *
 * Après la demande, la même réponse que l'adresse ait un compte ou non : la
 * page ne doit pas servir à deviner quelles adresses en ont un.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
$envoye = ($data['envoye'] ?? false) === true;
$minutes = is_int($data['minutes'] ?? null) ? $data['minutes'] : 30;

?>
<div class="admin-connexion">
    <h1>Mot de passe oublié</h1>

    <?php if ($erreur !== null) : ?>
    <p class="erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>

    <?php if ($envoye) : ?>
    <p class="succes" role="status">
        Si un compte correspond à cette adresse, un lien pour choisir un nouveau mot
        de passe vient d’y être envoyé. Il est valable <?= e($minutes) ?> minutes.
    </p>
    <?php else : ?>
    <p class="aide">
        Indiquez l’adresse de votre compte : vous recevrez un lien pour choisir un
        nouveau mot de passe.
    </p>

    <form method="post" action="<?= attr($base . '/admin/mot-de-passe-oublie') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="email">Adresse électronique</label>
            <input type="email" id="email" name="email" autocomplete="username" required autofocus>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Recevoir le lien</button>
        </p>
    </form>
    <?php endif; ?>

    <p class="aide"><a href="<?= attr($base . '/admin/connexion') ?>">Retour à la connexion</a></p>
</div>
