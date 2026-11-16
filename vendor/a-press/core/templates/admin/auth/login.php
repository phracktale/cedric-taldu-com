<?php

/**
 * @var array<string, mixed>              $data
 * @var APress\Core\Ui\UrlBuilder          $url
 * @var class-string<APress\Core\Ui\Html> $h
 */

declare(strict_types=1);

$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
$email = is_string($data['email'] ?? null) ? $data['email'] : '';
?>
<div class="connexion">
    <h1>Connexion</h1>
    <?php if ($erreur !== null) : ?>
    <p class="notice notice-erreur" role="alert"><?= $h::e($erreur) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $h::attr($url->admin('connexion')) ?>" class="postbox">
        <input type="hidden" name="_token" value="<?= $h::attr($jeton) ?>">
        <p>
            <label for="email">Adresse e-mail</label>
            <input type="email" id="email" name="email" value="<?= $h::attr($email) ?>" autocomplete="username" required>
        </p>
        <p>
            <label for="mot_de_passe">Mot de passe</label>
            <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="current-password" required>
        </p>
        <p><button type="submit" class="bouton">Se connecter</button></p>
    </form>
</div>
