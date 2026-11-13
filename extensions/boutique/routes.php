<?php

/**
 * Routes de l'extension « boutique » (A-Press, lot 3), sorties de
 * config/routes.php à l'identique : panier, commande, espace client,
 * webhooks Stripe et Prodigi, fichiers d'impression, et les écrans
 * d'administration des commandes, de la facturation, de la livraison, de
 * l'impression et des reproductions.
 *
 * Chargées par BoutiqueExtension::register() quand l'extension est active,
 * et par Tests\Support\Routes::all() pour que les tests de sécurité
 * (CSRF, authentification, préfixe) couvrent toujours ces routes.
 */

declare(strict_types=1);

use APress\Core\Route;
use App\Http\Controller\Admin\OrderController as AdminOrderController;
use App\Http\Controller\Admin\BillingController;
use App\Http\Controller\Admin\DeliveryController;
use App\Http\Controller\Admin\PrintSettingsController;
use App\Http\Controller\Front\AccountController as CustomerAccountController;
use App\Http\Controller\Admin\ProductController as AdminProductController;
use App\Http\Controller\Front\CartController;
use App\Http\Controller\Front\CheckoutController;
use App\Http\Controller\Front\PrintAssetController;
use App\Http\Controller\Front\ProdigiWebhookController;
use App\Http\Controller\Front\StripeWebhookController;

$slug = ['slug' => Route::SLUG];
$id = ['id' => Route::ID];

return [
    // Espace client par lien e-mail (revue du 2026-09-24).
    new Route('account.index', 'GET', '/fr/compte', [CustomerAccountController::class, 'index'], locale: 'fr'),
    new Route('account.index', 'GET', '/en/account', [CustomerAccountController::class, 'index'], locale: 'en'),
    new Route('account.request', 'POST', '/fr/compte/connexion', [CustomerAccountController::class, 'requestLink'], locale: 'fr'),
    new Route('account.request', 'POST', '/en/account/sign-in', [CustomerAccountController::class, 'requestLink'], locale: 'en'),
    new Route('account.login', 'GET', '/fr/compte/connexion/{token}', [CustomerAccountController::class, 'signIn'], locale: 'fr', requirements: ['token' => '[0-9a-f]{64}']),
    new Route('account.login', 'GET', '/en/account/sign-in/{token}', [CustomerAccountController::class, 'signIn'], locale: 'en', requirements: ['token' => '[0-9a-f]{64}']),
    new Route('account.logout', 'POST', '/fr/compte/deconnexion', [CustomerAccountController::class, 'signOut'], locale: 'fr'),
    new Route('account.logout', 'POST', '/en/account/sign-out', [CustomerAccountController::class, 'signOut'], locale: 'en'),
    new Route('account.newsletter', 'POST', '/fr/compte/newsletter', [CustomerAccountController::class, 'newsletter'], locale: 'fr'),
    new Route('account.newsletter', 'POST', '/en/account/newsletter', [CustomerAccountController::class, 'newsletter'], locale: 'en'),
    new Route('account.order', 'GET', '/fr/compte/commandes/{reference}', [CustomerAccountController::class, 'order'], locale: 'fr', requirements: ['reference' => 'CT-[0-9]{4}-[0-9]+']),
    new Route('account.order', 'GET', '/en/account/orders/{reference}', [CustomerAccountController::class, 'order'], locale: 'en', requirements: ['reference' => 'CT-[0-9]{4}-[0-9]+']),
    new Route('account.invoice', 'GET', '/fr/compte/commandes/{reference}/facture', [CustomerAccountController::class, 'invoice'], locale: 'fr', requirements: ['reference' => 'CT-[0-9]{4}-[0-9]+']),
    new Route('account.invoice', 'GET', '/en/account/orders/{reference}/invoice', [CustomerAccountController::class, 'invoice'], locale: 'en', requirements: ['reference' => 'CT-[0-9]{4}-[0-9]+']),

    // --------------------------------------------------------------- panier
    //
    // Le panier n'existe qu'en francais et en anglais, sous prefixe de langue.
    // L'ajout, la mise a jour et le retrait sont des POST : CsrfTest exige leur
    // protection, et seul le webhook Stripe y echappe.

    new Route('cart.show', 'GET', '/fr/panier', [CartController::class, 'show'], locale: 'fr'),
    new Route('cart.show', 'GET', '/en/cart', [CartController::class, 'show'], locale: 'en'),

    // Page statique sans JavaScript : sans jeton, l'ajout renvoie a sa page de
    // confirmation (GET, meme chemin), qui porte un jeton frais.
    new Route('cart.add', 'POST', '/fr/panier/ajout', [CartController::class, 'add'], locale: 'fr', csrfConfirm: CartController::LINE_FIELDS),
    new Route('cart.add', 'POST', '/en/cart/add', [CartController::class, 'add'], locale: 'en', csrfConfirm: CartController::LINE_FIELDS),
    new Route('cart.confirm', 'GET', '/fr/panier/ajout', [CartController::class, 'confirm'], locale: 'fr'),
    new Route('cart.confirm', 'GET', '/en/cart/add', [CartController::class, 'confirm'], locale: 'en'),

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

    // Fichier d'impression Prodigi : route machine (non localisee), a jeton
    // signe. Sert l'image prete-a-imprimer, rangee hors webroot, au robot Prodigi.
    new Route(
        'print.asset',
        'GET',
        '/impression/{token}',
        [PrintAssetController::class, 'serve'],
        requirements: ['token' => '[0-9]+\.[0-9a-f]+'],
    ),

    // Callbacks de statut Prodigi. Le secret dans le chemin authentifie l'appel
    // (Prodigi ne signe pas) : exempt CSRF, comme le webhook Stripe.
    new Route(
        'prodigi.webhook',
        'POST',
        '/webhooks/prodigi/{secret}',
        [ProdigiWebhookController::class, 'handle'],
        requirements: ['secret' => '[0-9a-f]{32}'],
        csrfExempt: true,
    ),
    new Route('admin.delivery.edit', 'GET', '/admin/livraison', [DeliveryController::class, 'edit']),
    new Route('admin.delivery.update', 'POST', '/admin/livraison', [DeliveryController::class, 'update']),
    new Route('admin.delivery.rates', 'POST', '/admin/livraison/tarifs', [DeliveryController::class, 'updateRates']),
    // Paramètres › Impression (demande du 2026-09-30).
    new Route('admin.print.edit', 'GET', '/admin/impression', [PrintSettingsController::class, 'edit']),
    new Route('admin.print.update', 'POST', '/admin/impression', [PrintSettingsController::class, 'update']),

    // Reproductions et variantes, rattachees a une œuvre.
    new Route('admin.product.index', 'GET', '/admin/oeuvres/{id}/reproductions', [AdminProductController::class, 'index'], requirements: $id),
    new Route('admin.product.store', 'POST', '/admin/oeuvres/{id}/reproductions', [AdminProductController::class, 'store'], requirements: $id),
    new Route('admin.product.limited', 'POST', '/admin/oeuvres/{id}/reproductions/edition-limitee', [AdminProductController::class, 'storeLimitedEdition'], requirements: $id),
    new Route('admin.product.publish', 'POST', '/admin/reproductions/{id}/publication', [AdminProductController::class, 'togglePublication'], requirements: $id),
    new Route('admin.product.delete', 'POST', '/admin/reproductions/{id}/suppression', [AdminProductController::class, 'delete'], requirements: $id),
    new Route('admin.variant.store', 'POST', '/admin/reproductions/{id}/variantes', [AdminProductController::class, 'storeVariant'], requirements: $id),
    new Route('admin.variant.update', 'POST', '/admin/variantes/{id}', [AdminProductController::class, 'updateVariant'], requirements: $id),
    new Route('admin.variant.delete', 'POST', '/admin/variantes/{id}/suppression', [AdminProductController::class, 'deleteVariant'], requirements: $id),

    // Commandes. Fermees par defaut comme toute route /admin (AuthTest).
    // L'export vient AVANT la route a parametre, sinon « export.csv » serait lu
    // comme un {id}.
    new Route('admin.order.export', 'GET', '/admin/commandes/export.csv', [AdminOrderController::class, 'export']),
    new Route('admin.order.index', 'GET', '/admin/commandes', [AdminOrderController::class, 'index']),
    new Route('admin.order.tracking', 'POST', '/admin/commandes/suivi', [AdminOrderController::class, 'refreshTracking']),
    new Route('admin.order.show', 'GET', '/admin/commandes/{id}', [AdminOrderController::class, 'show'], requirements: $id),
    new Route('admin.order.ship', 'POST', '/admin/commandes/{id}/expedition', [AdminOrderController::class, 'ship'], requirements: $id),
    new Route('admin.order.refund.confirm', 'GET', '/admin/commandes/{id}/remboursement', [AdminOrderController::class, 'confirmRefund'], requirements: $id),
    new Route('admin.order.refund', 'POST', '/admin/commandes/{id}/remboursement', [AdminOrderController::class, 'refund'], requirements: $id),
    new Route('admin.order.invoice', 'GET', '/admin/commandes/{id}/facture', [AdminOrderController::class, 'invoice'], requirements: $id),
    // Identité du vendeur sur les factures (revue du 2026-09-24).
    new Route('admin.billing.edit', 'GET', '/admin/facturation', [BillingController::class, 'edit']),
    new Route('admin.billing.update', 'POST', '/admin/facturation', [BillingController::class, 'update']),
    new Route('admin.billing.vat', 'POST', '/admin/facturation/tva', [BillingController::class, 'updateVat']),
    new Route('admin.order.prodigi', 'POST', '/admin/commandes/{id}/prodigi', [AdminOrderController::class, 'submitProdigi'], requirements: $id),
];
