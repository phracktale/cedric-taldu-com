<?php

/**
 * @var array<string, mixed>              $data
 * @var APress\Core\Ui\UrlBuilder          $url
 * @var class-string<APress\Core\Ui\Html> $h
 */

declare(strict_types=1);

$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$erreur = is_string($data['erreur'] ?? null) ? $data['erreur'] : null;
?>
<div class="connexion">
    <h1>Vérification</h1>
    <p class="aide">Saisissez le code de votre application d’authentification, ou un code de secours.</p>
    <?php if ($erreur !== null) : ?>
    <p class="notice notice-erreur" role="alert"><?= $h::e($erreur) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $h::attr($url->admin('connexion/2fa')) ?>" class="postbox">
        <input type="hidden" name="_token" value="<?= $h::attr($jeton) ?>">
        <p>
            <label for="code">Code</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
        </p>
        <p><button type="submit" class="bouton">Vérifier</button></p>
    </form>
</div>
