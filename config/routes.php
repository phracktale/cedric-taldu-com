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
use App\Http\Controller\Admin\ArtworkController as AdminArtworkController;
use App\Http\Controller\Admin\AuthController;
use App\Http\Controller\Admin\CategoryController as AdminCategoryController;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\MediaController;
use App\Http\Controller\Front\ArtworkController;
use App\Http\Controller\Front\CategoryController;
use App\Http\Controller\Front\CartController;
use App\Http\Controller\Front\CheckoutController;
use App\Http\Controller\Front\HomeController;
use App\Http\Controller\Front\StripeWebhookController;

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

    // --------------------------------------------------------------- panier
    //
    // Le panier n'existe qu'en francais et en anglais, sous prefixe de langue.
    // L'ajout, la mise a jour et le retrait sont des POST : CsrfTest exige leur
    // protection, et seul le webhook Stripe y echappe.

    new Route('cart.show', 'GET', '/fr/panier', [CartController::class, 'show'], locale: 'fr'),
    new Route('cart.show', 'GET', '/en/cart', [CartController::class, 'show'], locale: 'en'),

    new Route('cart.add', 'POST', '/fr/panier/ajout', [CartController::class, 'add'], locale: 'fr'),
    new Route('cart.add', 'POST', '/en/cart/add', [CartController::class, 'add'], locale: 'en'),

    new Route('cart.update', 'POST', '/fr/panier/quantite', [CartController::class, 'update'], locale: 'fr'),
    new Route('cart.update', 'POST', '/en/cart/quantity', [CartController::class, 'update'], locale: 'en'),

    new Route('cart.remove', 'POST', '/fr/panier/retrait', [CartController::class, 'remove'], locale: 'fr'),
    new Route('cart.remove', 'POST', '/en/cart/remove', [CartController::class, 'remove'], locale: 'en'),

    // ------------------------------------------------------------- commande
    //
    // Tunnel en deux temps plus une page de retour. La reference de commande de
    // la page de confirmation est validee par le controleur (OrderReference) :
    // elle vient de l'URL, et le jeton d'acces protege la lecture.

    new Route('checkout.form', 'GET', '/fr/commande', [CheckoutController::class, 'form'], locale: 'fr'),
    new Route('checkout.form', 'GET', '/en/checkout', [CheckoutController::class, 'form'], locale: 'en'),

    new Route('checkout.submit', 'POST', '/fr/commande', [CheckoutController::class, 'submit'], locale: 'fr'),
    new Route('checkout.submit', 'POST', '/en/checkout', [CheckoutController::class, 'submit'], locale: 'en'),

    new Route(
        'checkout.confirmation',
        'GET',
        '/fr/commande/confirmation/{reference}',
        [CheckoutController::class, 'confirmation'],
        locale: 'fr',
        requirements: ['reference' => 'CT-[0-9]{4}-[0-9]+'],
    ),
    new Route(
        'checkout.confirmation',
        'GET',
        '/en/checkout/confirmation/{reference}',
        [CheckoutController::class, 'confirmation'],
        locale: 'en',
        requirements: ['reference' => 'CT-[0-9]{4}-[0-9]+'],
    ),

    // ------------------------------------------------------------- webhooks
    //
    // 03-boutique §6 : route NI LOCALISEE NI PROTEGEE PAR CSRF — Stripe n'a ni
    // langue ni jeton de session a fournir. C'est la seule exemption du site
    // (06-securite §3), et elle n'est tenue que par la signature
    // cryptographique du corps, verifiee par le controleur.

    new Route(
        'stripe.webhook',
        'POST',
        '/webhooks/stripe',
        [StripeWebhookController::class, 'handle'],
        csrfExempt: true,
    ),

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

    // Œuvres
    new Route('admin.artwork.index', 'GET', '/admin/oeuvres', [AdminArtworkController::class, 'index']),
    new Route('admin.artwork.create', 'GET', '/admin/oeuvres/nouvelle', [AdminArtworkController::class, 'create']),
    new Route('admin.artwork.store', 'POST', '/admin/oeuvres', [AdminArtworkController::class, 'store']),
    new Route('admin.artwork.edit', 'GET', '/admin/oeuvres/{id}', [AdminArtworkController::class, 'edit'], requirements: $id),
    new Route('admin.artwork.update', 'POST', '/admin/oeuvres/{id}', [AdminArtworkController::class, 'update'], requirements: $id),
    new Route('admin.artwork.publish', 'POST', '/admin/oeuvres/{id}/publication', [AdminArtworkController::class, 'togglePublication'], requirements: $id),
    new Route('admin.artwork.move', 'POST', '/admin/oeuvres/{id}/position', [AdminArtworkController::class, 'move'], requirements: $id),
    new Route('admin.artwork.delete', 'POST', '/admin/oeuvres/{id}/suppression', [AdminArtworkController::class, 'delete'], requirements: $id),

    // Mediatheque
    new Route('admin.media.index', 'GET', '/admin/medias', [MediaController::class, 'index']),
    new Route('admin.media.upload', 'POST', '/admin/medias', [MediaController::class, 'upload']),
    new Route('admin.media.update', 'POST', '/admin/medias/{id}', [MediaController::class, 'update'], requirements: $id),
    new Route('admin.media.delete', 'POST', '/admin/medias/{id}/suppression', [MediaController::class, 'delete'], requirements: $id),
];
