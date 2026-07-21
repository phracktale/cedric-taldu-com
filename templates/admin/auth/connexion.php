<?php

/**
 * Formulaire de connexion.
 *
 * Un seul message d'echec, quel que soit le motif (04-back-office §1) : compte
 * inconnu, mot de passe faux et compte verrouille se ressemblent, sans quoi le
 * formulaire enumere les adresses.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
$email = is_string($data['email'] ?? null) ? $data['email'] : '';

?>
<div class="admin-connexion">
    <h1>Administration</h1>

    <?php if ($erreur !== null) : ?>
    <p class="erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/connexion') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="email">Adresse électronique</label>
            <input type="email" id="email" name="email" value="<?= attr($email) ?>"
                   autocomplete="username" required autofocus>
        </p>

        <p class="champ">
            <label for="mot_de_passe">Mot de passe</label>
            <input type="password" id="mot_de_passe" name="mot_de_passe"
                   autocomplete="current-password" required>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Se connecter</button>
        </p>
    </form>
</div>
