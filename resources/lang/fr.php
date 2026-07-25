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
    'nav.menu' => 'Menu',
    'nav.tagline' => 'artiste plasticien — Amiens',
    'nav.main_label' => 'Navigation principale',
    'nav.skip' => 'Aller au contenu',
    'nav.breadcrumb' => 'Fil d’Ariane',
    'nav.home' => 'Accueil',

    // Pied de page
    'footer.legal' => 'Mentions légales',
    'footer.privacy' => 'Confidentialité',
    'footer.terms' => 'CGV',
    'footer.contact' => 'Contact',
    'footer.legal_label' => 'Informations légales',
    'footer.role' => 'Artiste plasticien, Amiens, Hauts-de-France',

    // Bandeau de préproduction
    'env.preprod' => 'Préproduction — :env · contenus de démonstration',
];
