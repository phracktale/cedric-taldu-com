<?php

/**
 * En-tête global.
 *
 * 02-front-public §1 : le menu Galerie n'est PAS cliquable et ouvre un
 * sous-menu listant les rubriques publiées, alimenté depuis la base — aucune
 * rubrique n'est écrite en dur.
 *
 * Sans JavaScript, le sous-menu reste ouvrable et parcourable au clavier grâce
 * à `:focus-within` ; nav.js n'ajoute que le clic, les flèches et Échap.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Catalog\Category;
use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];

/** @var list<Category> $rubriques */
$rubriques = is_array($data['menuCategories'] ?? null) ? $data['menuCategories'] : [];

$rubriqueCourante = is_int($data['currentCategoryId'] ?? null) ? $data['currentCategoryId'] : null;

$libelles = $locale === Locale::Fr
    ? ['apropos' => 'À propos', 'galerie' => 'Galerie', 'actus' => 'Actus',
       'livret' => 'Livret', 'contact' => 'Contact', 'menu' => 'Menu', 'surtitre' => 'artiste plasticien — Amiens']
    : ['apropos' => 'About', 'galerie' => 'Gallery', 'actus' => 'News',
       'livret' => 'Booklet', 'contact' => 'Contact', 'menu' => 'Menu', 'surtitre' => 'visual artist — Amiens, France'];

?>
<header>
  <div class="nav">
    <a class="brand" href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>">Cédric Taldu<small><?= e($libelles['surtitre']) ?></small></a>

    <button class="burger" aria-expanded="false" aria-controls="menu"><?= e($libelles['menu']) ?></button>

    <nav aria-label="<?= attr($locale === Locale::Fr ? 'Navigation principale' : 'Main navigation') ?>">
      <ul id="menu">
        <li class="sous-menu">
          <?php /* Non cliquable : c'est un ouvreur de sous-menu, pas une page. */ ?>
          <button type="button" class="nav-bouton" aria-expanded="true"><?= e($libelles['galerie']) ?></button>
          <ul>
            <?php foreach ($rubriques as $rubrique) : ?>
            <li>
              <a
                href="<?= attr($url->route('category.show', ['locale' => $locale->value, 'slug' => $rubrique->slug($locale)->value])) ?>"
                <?php if ($rubrique->id === $rubriqueCourante) : ?>aria-current="page"<?php endif; ?>
              ><?= e($rubrique->title($locale)) ?></a>
            </li>
            <?php endforeach; ?>
          </ul>
        </li>
      </ul>
    </nav>
  </div>
</header>
