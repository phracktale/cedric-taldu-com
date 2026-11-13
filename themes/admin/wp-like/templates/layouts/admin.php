<?php

/**
 * Thème d'administration « wp-like » (A-Press, lot 4) : mise en page façon
 * WordPress. Remplace templates/layouts/admin.php quand le thème est actif.
 *
 * Mêmes données, mêmes garanties que la mise en page classique : pas de
 * <script> ni de <style> sans nonce (CSP stricte), toute sortie par un helper,
 * déconnexion en POST avec jeton. Seule la structure change :
 *
 *  - #wpadminbar : barre sombre du haut (site, « Voir le site », compte) ;
 *  - #adminmenu  : menu latéral, une entrée par rubrique ; la rubrique courante
 *    déplie ses écrans, les autres les montrent en survol ou au clavier ;
 *  - #wpcontent  : la page.
 *
 * La feuille admin.css reste chargée (formulaires, tableaux, éditeurs) ;
 * themes/wp-like.css ne redessine que le cadre et les contrôles courants.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var string                        $content rendu de la page, deja echappe
 * @var callable                      $partial
 */

declare(strict_types=1);

use APress\Core\Admin\AdminUser;
use App\Service\View\AdminMenu;

$titre = is_string($data['titre'] ?? null) ? $data['titre'] : 'Administration';
$nonce = is_string($data['nonce'] ?? null) ? $data['nonce'] : '';
$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$chemin = is_string($data['chemin'] ?? null) ? $data['chemin'] : '';

$utilisateur = ($data['utilisateur'] ?? null) instanceof AdminUser ? $data['utilisateur'] : null;

/** @var list<array{chemin: string, libelle: string, groupe?: string}> $menuExtensions */
$menuExtensions = is_array($data['menuExtensions'] ?? null) ? $data['menuExtensions'] : [];
$groupes = $utilisateur === null ? [] : AdminMenu::groups($menuExtensions);
$groupeCourant = AdminMenu::groupOf($chemin, $menuExtensions);

$site = ($data['site'] ?? null) instanceof App\Domain\Editorial\SiteIdentity
    ? $data['site']
    : App\Domain\Editorial\SiteIdentity::fromStored([]);

// Pictogrammes des rubriques : caractères seuls, aucune police d'icônes à charger.
$pictos = ['Contenus' => '✎', 'Boutique' => '▣', 'Modules' => '◫', 'Extensions' => '⧉', 'Paramètres' => '⚙'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titre) ?> ‹ <?= e($site->name) ?> — Administration</title>
<link rel="stylesheet" href="<?= attr($url->asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= attr($url->asset('css/themes/wp-like.css')) ?>">
<script type="module" src="<?= attr($url->asset('js/admin.js')) ?>" nonce="<?= attr($nonce) ?>" defer></script>
</head>
<body class="admin wp-admin<?= attr($utilisateur === null ? ' wp-connexion' : '') ?>" data-base="<?= attr($base) ?>">
<a class="skip-link" href="#contenu">Aller au contenu</a>

<?php if ($utilisateur !== null) : ?>
<div id="wpadminbar" role="navigation" aria-label="Barre d’administration">
    <ul class="ab-gauche">
        <li class="ab-site"><a href="<?= attr($base . '/admin') ?>"><span aria-hidden="true">⌂</span> <?= e($site->name) ?></a></li>
        <li><a href="<?= attr($url->route('home', ['locale' => 'fr'])) ?>">Voir le site</a></li>
        <?php if (($data['isProduction'] ?? true) === false) : ?>
        <li class="ab-env">Préproduction — <?= e($data['env'] ?? '') ?></li>
        <?php endif; ?>
    </ul>
    <ul class="ab-droite">
        <li class="ab-compte">
            <a href="<?= attr($base . '/admin/compte/2fa') ?>">Bonjour, <?= e($utilisateur->displayName) ?></a>
            <ul class="ab-sous-menu">
                <li><span class="ab-role"><?= e($utilisateur->role->label()) ?></span></li>
                <li><a href="<?= attr($base . '/admin/compte/2fa') ?>">Double authentification</a></li>
                <li><a href="<?= attr($base . '/admin/compte/mot-de-passe') ?>">Mot de passe</a></li>
                <li>
                    <form method="post" action="<?= attr($base . '/admin/deconnexion') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
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
            <li class="menu-top<?= attr($chemin === '/admin' ? ' current' : '') ?>">
                <a class="menu-top-lien" href="<?= attr($base . '/admin') ?>"<?php if ($chemin === '/admin') : ?> aria-current="page"<?php endif; ?>>
                    <span class="picto" aria-hidden="true">◧</span> Tableau de bord
                </a>
            </li>
            <?php foreach ($groupes as $groupe) : ?>
                <?php
                $courant = $groupe['label'] === $groupeCourant;
                $premier = null;
                foreach ($groupe['items'] as $entree) {
                    if ($entree !== null) {
                        $premier = $entree;
                        break;
                    }
                }
                if ($premier === null) {
                    continue;
                }
                ?>
            <li class="menu-top<?= attr($courant ? ' wp-has-current-submenu' : '') ?>">
                <a class="menu-top-lien" href="<?= attr($base . $premier['chemin']) ?>"<?php if ($courant) : ?> aria-current="true"<?php endif; ?>>
                    <span class="picto" aria-hidden="true"><?= e($pictos[$groupe['label']] ?? '•') ?></span> <?= e($groupe['label']) ?>
                </a>
                <ul class="wp-submenu">
                    <li class="wp-submenu-head" aria-hidden="true"><?= e($groupe['label']) ?></li>
                    <?php foreach ($groupe['items'] as $entree) : ?>
                        <?php if ($entree === null) : ?>
                    <li class="wp-submenu-separateur" role="separator"></li>
                        <?php else : ?>
                    <li<?php if (AdminMenu::isCurrent($chemin, $entree['chemin'])) : ?> class="current"<?php endif; ?>>
                        <a href="<?= attr($base . $entree['chemin']) ?>"
                           <?php if (AdminMenu::isCurrent($chemin, $entree['chemin'])) : ?>aria-current="page"<?php endif; ?>
                        ><?= e($entree['libelle']) ?></a>
                    </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>

<div id="wpcontent">
    <main id="contenu" class="admin-contenu wrap">
<?= $content ?>
    </main>
</div>
<?php if (($data['generation'] ?? null) instanceof App\Service\StaticSite\GenerationState) : ?>
<?= $partial('admin/partials/barre-generation', $data) ?>
<?php endif; ?>
</body>
</html>
