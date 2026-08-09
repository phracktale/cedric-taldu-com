<?php

/**
 * Accueil — sections ordonnées et activables (02-front-public §2, audit accueil).
 *
 * L'ordre et l'activation des sections viennent de `HomeLayout` (réglage
 * `home.layout`, administrable) ; le CONTENU de chaque section vient de son
 * réglage `home.*`. Chaque section est un partial de `partials/home/` : la page
 * n'est plus qu'un aiguillage.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Category;
use App\Domain\Editorial\HomeLayout;
use App\Domain\Editorial\Post;
use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];

/** @var array<string, mixed> $hero */
$hero = $data['hero'];
/** @var array<string, mixed> $triptych */
$triptych = $data['triptych'];
/** @var array<string, mixed> $shop */
$shop = $data['shop'];
/** @var array<string, mixed> $studio */
$studio = $data['studio'];
/** @var array<string, mixed> $news */
$news = $data['news'];
/** @var array<string, mixed> $contact */
$contact = $data['contact'];

/** @var list<Category> $rubriques */
$rubriques = $data['menuCategories'];
/** @var list<Artwork> $vitrine */
$vitrine = $data['showcase'];
/** @var array<int, App\Domain\Catalog\Media> $medias */
$medias = $data['showcaseMedias'];

$texte = static fn (array $source, string $cle): ?string
    => is_string($source[$cle] ?? null) && $source[$cle] !== '' ? $source[$cle] : null;

/** @var list<array<string, mixed>> $cellules */
$cellules = is_array($triptych['cells'] ?? null) ? $triptych['cells'] : [];
/** @var list<array<string, mixed>> $paragraphes */
$paragraphes = is_array($studio['paragraphs'] ?? null) ? $studio['paragraphs'] : [];
/** @var list<Post> $recentPosts */
$recentPosts = is_array($data['recentPosts'] ?? null) ? $data['recentPosts'] : [];
/** @var callable(Post): string $articleUrl */
$articleUrl = $data['articleUrl'];
$newsIndexUrl = is_string($data['newsIndexUrl'] ?? null) ? $data['newsIndexUrl'] : '';

// Sections à rendre, dans l'ordre choisi en back-office (défaut : ordre canonique).
/** @var list<string> $sectionsAffichees */
$sectionsAffichees = is_array($data['homeSections'] ?? null) ? $data['homeSections'] : array_keys(HomeLayout::SECTIONS);

// Contexte commun : chaque partial de section y puise ce dont il a besoin.
$bag = [
    'locale' => $locale,
    'texte' => $texte,
    'hero' => $hero,
    'triptych' => $triptych,
    'cellules' => $cellules,
    'shop' => $shop,
    'studio' => $studio,
    'paragraphes' => $paragraphes,
    'news' => $news,
    'recentPosts' => $recentPosts,
    'articleUrl' => $articleUrl,
    'newsIndexUrl' => $newsIndexUrl,
    'contact' => $contact,
    'rubriques' => $rubriques,
    'vitrine' => $vitrine,
    'medias' => $medias,
];
?>
<?php foreach ($sectionsAffichees as $section) : ?>
  <?php // Le nom du partial vient d'une clef de section EN LISTE BLANCHE. ?>
  <?php if (isset(HomeLayout::SECTIONS[$section])) : ?>
    <?= $partial('partials/home/' . $section, $bag) ?>
  <?php endif; ?>
<?php endforeach; ?>
