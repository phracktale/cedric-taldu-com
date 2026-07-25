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

/** @var array<string, string> $localeSwitch URL équivalente par code de langue */
$localeSwitch = is_array($data['localeSwitch'] ?? null) ? $data['localeSwitch'] : [];
?>
<header>
  <div class="nav">
    <a class="brand" href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>">Cédric Taldu<small><?= $t('nav.tagline') ?></small></a>

    <button class="burger" aria-expanded="false" aria-controls="menu"><?= $t('nav.menu') ?></button>

    <nav aria-label="<?= $t('nav.main_label') ?>">
      <ul id="menu">
        <li>
          <a href="<?= attr($url->route('page.about', ['locale' => $locale->value])) ?>"><?= $t('nav.about') ?></a>
        </li>
        <li class="sous-menu">
          <?php /* Non cliquable : c'est un ouvreur de sous-menu, pas une page. */ ?>
          <button type="button" class="nav-bouton" aria-expanded="true"><?= $t('nav.gallery') ?></button>
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
        <li>
          <a href="<?= attr($url->route('blog.index', ['locale' => $locale->value])) ?>"><?= $t('nav.news') ?></a>
        </li>
        <li>
          <a href="<?= attr($url->route('page.booklet', ['locale' => $locale->value])) ?>"><?= $t('nav.booklet') ?></a>
        </li>
        <li>
          <a href="<?= attr($url->route('contact.form', ['locale' => $locale->value])) ?>"><?= $t('nav.contact') ?></a>
        </li>
      </ul>
    </nav>

    <?php // Sélecteur de langue : vers l'URL équivalente dans l'autre langue,
          // fournie par le contrôleur (05-i18n §2). Muet si l'équivalent manque. ?>
    <?php if ($localeSwitch !== []) : ?>
    <nav class="langues" aria-label="<?= $t('nav.language') ?>">
      <?php foreach (Locale::cases() as $autre) : ?>
        <?php if ($autre === $locale) : ?>
          <span aria-current="true"><?= e($autre->nativeName()) ?></span>
        <?php elseif (isset($localeSwitch[$autre->value])) : ?>
          <a href="<?= attr($localeSwitch[$autre->value]) ?>" hreflang="<?= attr($autre->value) ?>"><?= e($autre->nativeName()) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
  </div>
</header>
