<?php

/**
 * Catalogue de traduction — français (05-i18n-seo §4).
 *
 * Tableau PLAT de clés. `en.php` porte EXACTEMENT les mêmes clés : le test de
 * parité (tests/Unit/Service/I18n/TranslationCatalogTest) échoue sinon. Les
 * clés sont regroupées par domaine (`nav.*`, `shop.*`, …) pour se retrouver.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Navigation et ossature
    'nav.about' => 'À propos',
    'nav.gallery' => 'Galerie',
    'nav.news' => 'Actus',
    'nav.booklet' => 'Livret',
    'nav.contact' => 'Contact',
    'nav.cart' => 'Panier',
    'nav.menu' => 'Menu',
    'nav.tagline' => 'artiste plasticien — Amiens',
    'nav.main_label' => 'Navigation principale',
    'nav.skip' => 'Aller au contenu',
    'nav.breadcrumb' => 'Fil d’Ariane',
    'nav.home' => 'Accueil',
    'nav.language' => 'Langue',

    // Pied de page
    'footer.legal' => 'Mentions légales',
    'footer.privacy' => 'Confidentialité',
    'footer.terms' => 'CGV',
    'footer.contact' => 'Contact',
    'footer.legal_label' => 'Informations légales',
    'footer.role' => 'Artiste plasticien, Amiens, Hauts-de-France',

    // Bandeau de préproduction
    'env.preprod' => 'Préproduction — :env · contenus de démonstration',

    // Panier
    'cart.title' => 'Votre panier',
    'cart.empty' => 'Votre panier est vide.',
    'cart.item' => 'Article',
    'cart.quantity' => 'Quantité',
    'cart.amount' => 'Montant',
    'cart.remove' => 'Retirer',
    'cart.update' => 'Mettre à jour',
    'cart.subtotal' => 'Sous-total',
    'cart.reminder' => 'Les frais de port et la TVA éventuelle sont calculés à l’étape suivante.',
    'cart.checkout' => 'Passer la commande',
    'cart.added' => 'Ajouté au panier.',
    'cart.view' => 'Voir le panier',

    // Formulaires (éléments partagés)
    'form.do_not_fill' => 'Ne pas remplir',

    // Tunnel de commande
    'checkout.title' => 'Votre commande',
    'checkout.summary' => 'Récapitulatif',
    'checkout.your_details' => 'Vos coordonnées',
    'checkout.name' => 'Nom',
    'checkout.email' => 'Adresse e-mail',
    'checkout.phone' => 'Téléphone (facultatif)',
    'checkout.delivery_method' => 'Mode de remise',
    'checkout.shipping' => 'Expédition',
    'checkout.pickup' => 'Remise en main propre à Amiens',
    'checkout.shipping_address' => 'Adresse de livraison',
    'checkout.address' => 'Adresse',
    'checkout.address_line2' => 'Complément (facultatif)',
    'checkout.postal_code' => 'Code postal',
    'checkout.city' => 'Ville',
    'checkout.country' => 'Pays',
    'checkout.note' => 'Note (facultative)',
    'checkout.accept_terms' => 'J’accepte les conditions générales de vente',
    'checkout.read' => '(lire)',
    'checkout.pdf' => '(PDF)',
    'page.download_pdf' => 'Télécharger les CGV en PDF',
    'checkout.pay' => 'Procéder au paiement',
    'checkout.delivery' => 'Livraison',
    'checkout.free' => 'Gratuit',
    'checkout.on_request' => 'Sur devis',
    'checkout.shipping_cost' => 'Frais de port',
    'checkout.total' => 'Total',
    'checkout.delivery_estimate' => 'Réception estimée entre le :from et le :to.',
    'checkout.pickup_notice' => 'Retrait à l’atelier sur rendez-vous, après confirmation du paiement.',
    'checkout.shipping_note' => 'Tarif pour la France ; ajusté selon la destination.',
    'checkout.payment_secure' => 'Paiement sécurisé par carte bancaire via Stripe.',

    // Confirmation de commande
    'confirmation.pending_title' => 'Paiement en cours de confirmation',
    'confirmation.pending_text' => 'Votre paiement est en cours de traitement. Cette page se met à jour automatiquement.',
    'confirmation.paid_title' => 'Merci pour votre commande',
    'confirmation.paid_text' => 'Votre paiement a bien été reçu. Un e-mail de confirmation vous a été envoyé.',
    'confirmation.failed_title' => 'Commande non aboutie',
    'confirmation.failed_text' => 'Le paiement n’a pas abouti. Aucun montant ne vous a été prélevé.',
    'confirmation.reference' => 'Référence',
    'confirmation.total' => 'Total',
    'confirmation.back_home' => 'Retour à l’accueil',

    // Accueil
    'home.hero_cta' => 'Voir les œuvres',
    'home.showcase_label' => 'Œuvres en vitrine',
    'home.galleries_eyebrow' => 'Galeries',
    'home.galleries_title' => 'Le travail, par technique',
    'home.gallery_link' => 'Voir :name',
    'home.studio_portrait' => 'Portrait d’atelier',
    'home.studio_cta' => 'Parcours et démarche',
    'home.news_eyebrow' => 'Actualités',
    'home.news_title' => 'Expositions et travail en cours',
    'home.all_news' => 'Toutes les actus',

    // Fiche œuvre
    'artwork.zoom' => 'Cliquer pour agrandir',
    'artwork.acquire' => 'Acquérir cette œuvre',
    'artwork.ask_question' => 'Poser une question',
    'artwork.prints' => 'Reproductions',
    'artwork.editions_remaining' => ':count exemplaire(s) restant(s)',
    'artwork.add_to_cart' => 'Ajouter au panier',
    'artwork.related' => 'De la même recherche',
    'artwork.available_in_shop' => 'Disponible en boutique',
    'artwork.original_available' => 'Original disponible',
    'artwork.original_heading' => 'Œuvre originale — pièce unique',
    'artwork.acquire_original' => 'Acquérir l’œuvre originale',
    'artwork.prints_heading' => 'Tirages de cette œuvre',
    'artwork.fine_art_heading' => 'Tirage Fine Art',
    'artwork.fine_art_desc' => 'Impression d’art à la demande — choix du format.',
    'artwork.limited_heading' => 'Édition limitée rehaussée à la main',
    'artwork.limited_desc' => 'Numérotée, signée et reprise à l’encre par l’artiste.',

    // Rubrique
    'category.series' => 'Séries',
    'category.all' => 'Toutes',
    'category.works' => 'Œuvres',
    'category.pagination' => 'Pagination',
    'category.empty' => 'Aucune œuvre à afficher pour l’instant.',

    // Contact
    'contact.success' => 'Merci, votre message a bien été envoyé. Une réponse vous parviendra par courriel.',
    'contact.about_artwork' => 'Votre question concerne l’œuvre :',
    'contact.name' => 'Votre nom',
    'contact.email' => 'Votre adresse e-mail',
    'contact.message' => 'Votre message',
    'contact.send' => 'Envoyer',
    'contact.rgpd' => 'Les informations transmises servent uniquement à répondre à votre demande. '
        . 'Vous disposez d’un droit d’accès, de rectification et d’effacement de vos données.',
    'contact.learn_more' => 'En savoir plus',

    // Journal / actus
    'blog.journal' => 'Journal',
    'blog.empty' => 'Aucun article pour le moment.',
    'blog.pages' => 'Pages',
    'blog.previous' => 'Précédent',
    'blog.next' => 'Suivant',
    'blog.back_to_list' => '← Toutes les actus',
];
