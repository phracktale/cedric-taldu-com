<?php

/**
 * Activation et retrait du second facteur.
 *
 * Aucun QR code engendre : il demanderait soit une dependance, soit deux cents
 * lignes de matrices de correction d'erreurs. La cle est affichee en groupes de
 * quatre et le lien `otpauth:` est fourni — toutes les applications
 * d'authentification acceptent la saisie manuelle.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
$actif = ($data['actif'] ?? false) === true;

?>
<div class="admin-page admin-page--etroite">
    <h1>Double facteur</h1>

    <?php if ($erreur !== null) : ?>
    <p class="erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>

    <?php if ($actif) : ?>
    <p class="aide">
        Le double facteur est <strong>actif</strong> sur votre compte.
        Il vous reste <?= e($data['codesRestants'] ?? 0) ?> code(s) de secours.
    </p>

    <form method="post" action="<?= attr($base . '/admin/compte/2fa/retrait') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
        <p class="actions">
            <button type="submit" class="bouton bouton--danger">Désactiver le double facteur</button>
        </p>
    </form>

    <?php else : ?>
    <p class="aide">
        Ajoutez ce compte à votre application d’authentification, puis saisissez le
        code affiché pour confirmer. Tant que le code n’est pas confirmé, rien
        n’est enregistré.
    </p>

    <p class="secret">
        <span class="secret-libelle">Clé à saisir</span>
        <code><?= e($data['secretLisible'] ?? '') ?></code>
    </p>

    <p class="aide">
        <a href="<?= attr($data['uri'] ?? '') ?>">Ouvrir dans l’application d’authentification</a>
    </p>

    <form method="post" action="<?= attr($base . '/admin/compte/2fa') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="code">Code affiché par l’application</label>
            <input type="text" id="code" name="code" inputmode="numeric"
                   autocomplete="one-time-code" spellcheck="false" required>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Activer le double facteur</button>
        </p>
    </form>

    <?php endif; ?>
</div>
