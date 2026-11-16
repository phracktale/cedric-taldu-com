<?php

/**
 * Mise en page de l'administration A-Press, façon WordPress : barre du haut,
 * menu latéral sombre (rubriques du modèle de contenu, puis extensions),
 * contenu sur fond gris clair. Surchargeable par un thème ou par le site
 * (templates/admin/layout.php).
 *
 * Aucune balise <script> ni <style> en ligne : la CSP n'autorise que 'self'.
 *
 * @var array<string, mixed>              $data
 * @var APress\Core\Ui\UrlBuilder          $url
 * @var class-string<APress\Core\Ui\Html> $h
 * @var string                            $content
 */

declare(strict_types=1);

use APress\Core\Admin\AdminUser;

$titre = is_string($data['titre'] ?? null) ? $data['titre'] : 'Administration';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$chemin = is_string($data['chemin'] ?? null) ? $data['chemin'] : '';
$flash = is_string($data['flash'] ?? null) ? $data['flash'] : null;
$utilisateur = ($data['utilisateur'] ?? null) instanceof AdminUser ? $data['utilisateur'] : null;
/** @var list<array{label: string, items: list<array{chemin: string, libelle: string}>}> $menu */
$menu = is_array($data['menu'] ?? null) ? $data['menu'] : [];

$courant = static fn (string $c): bool => $chemin === $c || str_starts_with($chemin, $c . '/');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $h::e($titre) ?> — Administration</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= $h::attr($url->asset('admin.css')) ?>">
<script type="module" src="<?= $h::attr($url->asset('admin.js')) ?>"></script>
</head>
<body class="wp-admin<?= $h::attr($utilisateur === null ? ' wp-connexion' : '') ?>">
<a class="skip-link" href="#contenu">Aller au contenu</a>

<?php if ($utilisateur !== null) : ?>
<div id="wpadminbar" role="navigation" aria-label="Barre d’administration">
    <ul class="ab-gauche">
        <li class="ab-site"><a href="<?= $h::attr($url->admin()) ?>"><span aria-hidden="true">⌂</span> Administration</a></li>
        <li><a href="<?= $h::attr($url->path('/')) ?>">Voir le site</a></li>
        <?php if (($data['isProduction'] ?? true) === false) : ?>
        <li class="ab-env">Préproduction — <?= $h::e(is_string($data['env'] ?? null) ? $data['env'] : '') ?></li>
        <?php endif; ?>
    </ul>
    <ul class="ab-droite">
        <li class="ab-compte">
            <a href="<?= $h::attr($url->admin()) ?>">Bonjour, <?= $h::e($utilisateur->displayName) ?></a>
            <ul class="ab-sous-menu">
                <li><span class="ab-role"><?= $h::e($utilisateur->role->label()) ?></span></li>
                <li>
                    <form method="post" action="<?= $h::attr($url->admin('deconnexion')) ?>">
                        <input type="hidden" name="_token" value="<?= $h::attr($jeton) ?>">
                        <button type="submit" class="lien-bouton">Se déconnecter</button>
                    </form>
                </li>
            </ul>
        </li>
    </ul>
</div>

<div id="adminmenuwrap">
    <nav aria-label="Sections">
        <ul id="adminmenu">
            <li class="menu-top<?= $h::attr($chemin === '/admin' ? ' current' : '') ?>">
                <a class="menu-top-lien" href="<?= $h::attr($url->admin()) ?>"<?php if ($chemin === '/admin') : ?> aria-current="page"<?php endif; ?>>
                    <span class="picto" aria-hidden="true">◧</span> Tableau de bord
                </a>
            </li>
            <?php foreach ($menu as $groupe) : ?>
                <?php
                $ouvert = false;
                foreach ($groupe['items'] as $entree) {
                    $ouvert = $ouvert || $courant($entree['chemin']);
                }
                ?>
            <li class="menu-top<?= $h::attr($ouvert ? ' wp-has-current-submenu' : '') ?>">
                <a class="menu-top-lien" href="<?= $h::attr($url->path($groupe['items'][0]['chemin'])) ?>"<?php if ($ouvert) : ?> aria-current="true"<?php endif; ?>>
                    <span class="picto" aria-hidden="true">▤</span> <?= $h::e($groupe['label']) ?>
                </a>
                <ul class="wp-submenu">
                    <li class="wp-submenu-head" aria-hidden="true"><?= $h::e($groupe['label']) ?></li>
                    <?php foreach ($groupe['items'] as $entree) : ?>
                    <li<?php if ($courant($entree['chemin'])) : ?> class="current"<?php endif; ?>>
                        <a href="<?= $h::attr($url->path($entree['chemin'])) ?>"<?php if ($courant($entree['chemin'])) : ?> aria-current="page"<?php endif; ?>><?= $h::e($entree['libelle']) ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>

<div id="wpcontent">
    <main id="contenu" class="wrap">
        <?php if ($flash !== null) : ?>
        <p class="notice" role="status"><?= $h::e($flash) ?></p>
        <?php endif; ?>
<?= $content ?>
    </main>
</div>
</body>
</html>
