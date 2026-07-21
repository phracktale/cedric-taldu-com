<?php

/**
 * Accueil — lot 0.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = is_string($data['locale'] ?? null) ? $data['locale'] : 'fr';

?>
<h1>Bonjour</h1>
<p>Cédric Taldu — artiste plasticien, Amiens.</p>
<p><a href="<?= attr($url->route('home', ['locale' => $locale === 'fr' ? 'en' : 'fr'])) ?>"><?=
    e($locale === 'fr' ? 'English' : 'Français')
?></a></p>
