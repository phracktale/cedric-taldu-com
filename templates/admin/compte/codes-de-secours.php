<?php

/**
 * Codes de secours, montres UNE SEULE FOIS.
 *
 * La base n'en conserve que les empreintes : cette page est le seul endroit du
 * systeme ou ils sont lisibles. D'ou l'avertissement, et l'absence de tout lien
 * « revoir mes codes » — il n'existe pas.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';

/** @var list<string> $codes */
$codes = is_array($data['codes'] ?? null) ? $data['codes'] : [];

?>
<div class="admin-page admin-page--etroite">
    <h1>Codes de secours</h1>

    <p class="aide aide--attention">
        Le double facteur est actif. <strong>Imprimez ou recopiez ces codes maintenant :
        ils ne seront plus jamais affichés.</strong> Chacun ne fonctionne qu’une fois, et
        remplace le code de votre application si vous perdez votre téléphone.
    </p>

    <ul class="codes-de-secours">
    <?php foreach ($codes as $code) : ?>
        <li><code><?= e($code) ?></code></li>
    <?php endforeach; ?>
    </ul>

    <p class="actions">
        <a class="bouton" href="<?= attr($base . '/admin') ?>">J’ai noté mes codes</a>
    </p>
</div>
