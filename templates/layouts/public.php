<?php

/**
 * Mise en page publique.
 *
 * Reprend la structure des maquettes : en-tête collante, contenu, pied de page.
 * Trois écarts, tous exigés par les specs et signalés dans site.css : polices
 * auto-hébergées, aucun gestionnaire d'événement inline, `<picture>` réels.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var string                        $content rendu de la page, déjà échappé
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];
$titre = is_string($data['metaTitle'] ?? null) ? $data['metaTitle'] : '';
$description = is_string($data['metaDescription'] ?? null) ? $data['metaDescription'] : null;
$nonce = is_string($data['nonce'] ?? null) ? $data['nonce'] : '';
$canonique = is_string($data['canonical'] ?? null) ? $data['canonical'] : null;

/** @var array<string, string> $alternates */
$alternates = is_array($data['alternates'] ?? null) ? $data['alternates'] : [];

// Open Graph : image absolue fournie par les pages qui en ont une (fiche œuvre) ;
// ailleurs, carte texte simple. La locale OG suit la langue de la page.
$ogImage = is_string($data['ogImage'] ?? null) ? $data['ogImage'] : null;
$ogType = is_string($data['ogType'] ?? null) ? $data['ogType'] : 'website';
$ogLocale = $locale->value === 'en' ? 'en_GB' : 'fr_FR';
$ogCard = $ogImage !== null ? 'summary_large_image' : 'summary';

?>
<!DOCTYPE html>
<html lang="<?= attr($locale->htmlLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($titre) ?></title>
<?php if ($description !== null) : ?>
<meta name="description" content="<?= attr($description) ?>">
<?php endif; ?>
<?php if ($canonique !== null) : ?>
<link rel="canonical" href="<?= attr($canonique) ?>">
<?php endif; ?>
<?php foreach ($alternates as $code => $href) : ?>
<link rel="alternate" hreflang="<?= attr($code) ?>" href="<?= attr($href) ?>">
<?php endforeach; ?>
<?php // Open Graph + Twitter : carte propre au partage d'une œuvre. ?>
<meta property="og:type" content="<?= attr($ogType) ?>">
<meta property="og:site_name" content="Cédric Taldu">
<meta property="og:locale" content="<?= attr($ogLocale) ?>">
<meta property="og:title" content="<?= attr($titre) ?>">
<?php if ($description !== null) : ?>
<meta property="og:description" content="<?= attr($description) ?>">
<?php endif; ?>
<?php if ($canonique !== null) : ?>
<meta property="og:url" content="<?= attr($canonique) ?>">
<?php endif; ?>
<?php if ($ogImage !== null) : ?>
<meta property="og:image" content="<?= attr($ogImage) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= attr($ogCard) ?>">
<?= jsonLd(is_array($data['jsonLd'] ?? null) ? $data['jsonLd'] : null, $nonce) ?>
<link rel="preload" href="<?= attr($url->asset('fonts/Marcellus-Regular.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= attr($url->asset('css/site.css')) ?>">
<script type="module" src="<?= attr($url->asset('js/app.js')) ?>" nonce="<?= attr($nonce) ?>" defer></script>
</head>
<body data-base="<?= attr($data['basePath'] ?? '') ?>">
<?php if (($data['isProduction'] ?? true) === false) : ?>
<p class="bandeau-env"><?= $t('env.preprod', ['env' => is_string($data['env'] ?? null) ? $data['env'] : '']) ?></p>
<?php endif; ?>

<a class="skip-link" href="#contenu"><?= $t('nav.skip') ?></a>

<?= $partial('partials/header', $data) ?>

<main id="contenu">
<?= $content ?>
</main>

<?= $partial('partials/footer', $data) ?>
</body>
</html>
