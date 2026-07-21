<?php

/**
 * Table de routes.
 *
 * Aucune decouverte automatique : la liste se lit d'un coup d'œil, et les tests
 * de securite la parcourent — CsrfTest verifie que chaque route non-GET est
 * protegee, AuthTest qu'aucune route d'administration n'est ouverte, BasePathTest
 * rejoue chaque route sous prefixe.
 *
 * Les SEGMENTS sont traduits, pas seulement les slugs (05-i18n-seo §2) :
 * « /fr/galerie/encres » contre « /en/gallery/inks ». Une entree par langue,
 * sous le meme nom de route.
 *
 * @return list<App\Core\Route>
 */

declare(strict_types=1);

use App\Core\Route;
use App\Http\Controller\Admin\AccountController;
use App\Http\Controller\Admin\AuthController;
use App\Http\Controller\Admin\CategoryController as AdminCategoryController;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\MediaController;
use App\Http\Controller\Front\ArtworkController;
use App\Http\Controller\Front\CategoryController;
use App\Http\Controller\Front\HomeController;

$slug = ['slug' => Route::SLUG];
$id = ['id' => Route::ID];
$idEtSerie = ['id' => Route::ID, 'serie' => Route::ID];

return [
    // Accueil
    new Route('home', 'GET', '/fr/', [HomeController::class, 'show'], locale: 'fr'),
    new Route('home', 'GET', '/en/', [HomeController::class, 'show'], locale: 'en'),

    // Rubrique
    new Route('category.show', 'GET', '/fr/galerie/{slug}', [CategoryController::class, 'show'], locale: 'fr', requirements: $slug),
    new Route('category.show', 'GET', '/en/gallery/{slug}', [CategoryController::class, 'show'], locale: 'en', requirements: $slug),

    // Fiche œuvre
    new Route('artwork.show', 'GET', '/fr/oeuvre/{slug}', [ArtworkController::class, 'show'], locale: 'fr', requirements: $slug),
    new Route('artwork.show', 'GET', '/en/artwork/{slug}', [ArtworkController::class, 'show'], locale: 'en', requirements: $slug),

    // ----------------------------------------------------------- back-office
    //
    // Non localise : l'interface d'administration est en francais seulement
    // (04-back-office, en-tete). Toute route sous /admin est FERMEE par defaut ;
    // `guest: true` ouvre la connexion, et rien d'autre. AuthTest parcourt cette
    // table et verifie qu'aucune autre ne l'est.

    new Route('admin.login', 'GET', '/admin/connexion', [AuthController::class, 'showLogin'], guest: true),
    new Route('admin.login.submit', 'POST', '/admin/connexion', [AuthController::class, 'login'], guest: true),
    new Route('admin.2fa', 'GET', '/admin/connexion/2fa', [AuthController::class, 'showTwoFactor'], guest: true),
    new Route('admin.2fa.submit', 'POST', '/admin/connexion/2fa', [AuthController::class, 'verifyTwoFactor'], guest: true),
    new Route('admin.logout', 'POST', '/admin/deconnexion', [AuthController::class, 'logout'], guest: true),

    new Route('admin.dashboard', 'GET', '/admin', [DashboardController::class, 'show']),

    new Route('admin.account.2fa', 'GET', '/admin/compte/2fa', [AccountController::class, 'showTwoFactor']),
    new Route('admin.account.2fa.enable', 'POST', '/admin/compte/2fa', [AccountController::class, 'enableTwoFactor']),
    new Route('admin.account.2fa.disable', 'POST', '/admin/compte/2fa/retrait', [AccountController::class, 'disableTwoFactor']),

    // Rubriques et series
    new Route('admin.category.index', 'GET', '/admin/rubriques', [AdminCategoryController::class, 'index']),
    new Route('admin.category.create', 'GET', '/admin/rubriques/nouvelle', [AdminCategoryController::class, 'create']),
    new Route('admin.category.store', 'POST', '/admin/rubriques', [AdminCategoryController::class, 'store']),
    new Route('admin.category.edit', 'GET', '/admin/rubriques/{id}', [AdminCategoryController::class, 'edit'], requirements: $id),
    new Route('admin.category.update', 'POST', '/admin/rubriques/{id}', [AdminCategoryController::class, 'update'], requirements: $id),
    new Route('admin.category.publish', 'POST', '/admin/rubriques/{id}/publication', [AdminCategoryController::class, 'togglePublication'], requirements: $id),
    new Route('admin.category.move', 'POST', '/admin/rubriques/{id}/position', [AdminCategoryController::class, 'move'], requirements: $id),
    new Route('admin.category.delete', 'POST', '/admin/rubriques/{id}/suppression', [AdminCategoryController::class, 'delete'], requirements: $id),
    new Route('admin.series.store', 'POST', '/admin/rubriques/{id}/series', [AdminCategoryController::class, 'storeSeries'], requirements: $id),
    new Route('admin.series.delete', 'POST', '/admin/rubriques/{id}/series/{serie}/suppression', [AdminCategoryController::class, 'deleteSeries'], requirements: $idEtSerie),

    // Mediatheque
    new Route('admin.media.index', 'GET', '/admin/medias', [MediaController::class, 'index']),
    new Route('admin.media.upload', 'POST', '/admin/medias', [MediaController::class, 'upload']),
    new Route('admin.media.update', 'POST', '/admin/medias/{id}', [MediaController::class, 'update'], requirements: $id),
    new Route('admin.media.delete', 'POST', '/admin/medias/{id}/suppression', [MediaController::class, 'delete'], requirements: $id),
];
