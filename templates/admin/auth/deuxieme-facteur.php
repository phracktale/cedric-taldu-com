<?php

/**
 * Saisie du second facteur.
 *
 * Le champ accepte indifferemment un code TOTP a six chiffres et un code de
 * secours : deux champs distincts obligeraient l'artiste a savoir lequel des
 * deux il tient, alors que le serveur, lui, le voit tout de suite.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;

?>
<div class="admin-connexion">
    <h1>Vérification</h1>

    <p class="aide">
        Saisissez le code à six chiffres de votre application d’authentification,
        ou l’un de vos codes de secours.
    </p>

    <?php if ($erreur !== null) : ?>
    <p class="erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/connexion/2fa') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="code">Code</label>
            <input type="text" id="code" name="code" inputmode="text"
                   autocomplete="one-time-code" spellcheck="false" required autofocus>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Valider</button>
        </p>
    </form>
</div>
