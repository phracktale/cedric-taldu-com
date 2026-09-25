<?php

/**
 * Mise en page du back-office.
 *
 * 04-back-office §12 : « Interface sobre reprenant la charte du site, en une
 * seule feuille de style. » Les jetons de couleur et de typographie viennent des
 * maquettes ; la mise en page, elle, est celle d'un outil de travail et non
 * d'une vitrine.
 *
 * Aucune balise <script> ni <style> sans nonce : la CSP est stricte et sans
 * unsafe-inline, une balise oubliee partirait silencieusement bloquée.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var string                        $content rendu de la page, deja echappe
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Admin\AdminUser;

$titre = is_string($data['titre'] ?? null) ? $data['titre'] : 'Administration';
$nonce = is_string($data['nonce'] ?? null) ? $data['nonce'] : '';
$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$chemin = is_string($data['chemin'] ?? null) ? $data['chemin'] : '';

$utilisateur = ($data['utilisateur'] ?? null) instanceof AdminUser ? $data['utilisateur'] : null;

// Menu en rubriques (retours du 2026-09-25) : source unique dans AdminMenu.
$groupes = $utilisateur === null ? [] : App\Service\View\AdminMenu::groups();
$groupeCourant = App\Service\View\AdminMenu::groupOf($chemin);

// Identité du site (Paramètres › Global), partagée par View::share.
$site = ($data['site'] ?? null) instanceof App\Domain\Editorial\SiteIdentity
    ? $data['site']
    : App\Domain\Editorial\SiteIdentity::fromStored([]);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titre) ?> — Administration</title>
<link rel="stylesheet" href="<?= attr($url->asset('css/admin.css')) ?>">
<script type="module" src="<?= attr($url->asset('js/admin.js')) ?>" nonce="<?= attr($nonce) ?>" defer></script>
</head>
<body class="admin" data-base="<?= attr($base) ?>">
<?php if (($data['isProduction'] ?? true) === false) : ?>
<p class="bandeau-env">Préproduction — <?= e($data['env'] ?? '') ?></p>
<?php endif; ?>

<a class="skip-link" href="#contenu">Aller au contenu</a>

<header class="admin-entete">
    <p class="admin-marque"><a href="<?= attr($base . '/admin') ?>"><?= e($site->name) ?></a> <span>administration</span></p>

    <?php if ($utilisateur !== null) : ?>
    <nav class="admin-nav" aria-label="Sections">
        <ul class="admin-groupes">
        <?php foreach ($groupes as $groupe) : ?>
            <li>
                <details class="admin-groupe"<?php if ($groupe['label'] === $groupeCourant) : ?> open<?php endif; ?>>
                    <summary><?= e($groupe['label']) ?></summary>
                    <ul>
                    <?php foreach ($groupe['items'] as $entree) : ?>
                        <?php if ($entree === null) : ?>
                        <li class="admin-separateur" role="separator"></li>
                        <?php else : ?>
                        <li>
                            <a href="<?= attr($base . $entree['chemin']) ?>"
                               <?php if (App\Service\View\AdminMenu::isCurrent($chemin, $entree['chemin'])) : ?>aria-current="page"<?php endif; ?>
                            ><?= e($entree['libelle']) ?></a>
                        </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </ul>
                </details>
            </li>
        <?php endforeach; ?>
        </ul>
    </nav>

    <div class="admin-compte">
        <a href="<?= attr($base . '/admin/compte/2fa') ?>"><?= e($utilisateur->displayName) ?></a>
        <span class="admin-role"><?= e($utilisateur->role->label()) ?></span>
        <form method="post" action="<?= attr($base . '/admin/deconnexion') ?>">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <button type="submit" class="lien-bouton">Se déconnecter</button>
        </form>
    </div>
    <?php endif; ?>
</header>

<main id="contenu" class="admin-contenu">
<?= $content ?>
</main>
<?php if (($data['generation'] ?? null) instanceof App\Service\StaticSite\GenerationState) : ?>
<?= $partial('admin/partials/barre-generation', $data) ?>
<?php endif; ?>
</body>
</html>
