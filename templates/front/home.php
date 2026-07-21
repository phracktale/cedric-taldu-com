<?php

/**
 * Accueil — lot 0.
 *
 * Les huit modules decrits dans 02-front-public §2 arrivent au lot 1.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = is_string($data['locale'] ?? null) ? $data['locale'] : 'fr';
$autreLangue = $locale === 'fr' ? 'en' : 'fr';

?>
<h1>Bonjour</h1>
<p>Cédric Taldu — artiste plasticien, Amiens.</p>
<p>
<a href="<?= attr($url->route('home', ['locale' => $autreLangue])) ?>"><?=
    e($autreLangue === 'en' ? 'English' : 'Français')
?></a>
</p>
