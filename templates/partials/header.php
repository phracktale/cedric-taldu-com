<?php

/**
 * En-tête global.
 *
 * 02-front-public §1, revu le 2026-09-24 : « Galerie » mène à la page mère des
 * galeries, et un bouton voisin ouvre le sous-menu listant les rubriques
 * publiées, alimenté depuis la base — aucune rubrique n'est écrite en dur.
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

// Pastille du panier : fournie par Chrome, lue sans jamais créer de panier.
$cartCount = is_int($data['cartCount'] ?? null) ? $data['cartCount'] : 0;

// « Actus » n'apparaît que s'il existe au moins un article publié.
$hasNews = ($data['hasNews'] ?? false) === true;

// Rubrique active (déduite de la route par Chrome) et style choisi en réglage.
$section = is_string($data['currentSection'] ?? null) ? $data['currentSection'] : null;
$styleActif = is_string($data['navActiveStyle'] ?? null) ? $data['navActiveStyle'] : 'souligne';

// Menu composé en back-office (revue du 2026-09-24) : ordre, affichage et
// libellés. Sans réglage, le menu historique. Chaque rubrique : [URL, clef du
// libellé par défaut] ; une entrée hors de cette table n'est jamais rendue.
/** @var list<array{item: string, labels: array<string, string>}> $entrees */
$entrees = is_array($data['menuItems'] ?? null)
    ? $data['menuItems']
    : App\Domain\Editorial\MainMenu::default()->enabledItems();
$langue = ['locale' => $locale->value];
$liens = [
    'about' => [$url->route('page.about', $langue), 'nav.about'],
    'gallery' => [$url->route('gallery.index', $langue), 'nav.gallery'],
    'works' => [$url->route('artwork.index', $langue), 'gallery.all_works_title'],
    'news' => [$url->route('blog.index', $langue), 'nav.news'],
    'booklet' => [$url->route('page.booklet', $langue), 'nav.booklet'],
    'contact' => [$url->route('contact.form', $langue), 'nav.contact'],
];
?>
<header class="site-tete">
  <div class="nav">
    <a class="brand" href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>">Cédric Taldu<small><?= $t('nav.tagline') ?></small></a>

    <button class="burger" aria-expanded="false" aria-controls="menu"><?= $t('nav.menu') ?></button>

    <nav aria-label="<?= $t('nav.main_label') ?>" data-actif="<?= attr($styleActif) ?>">
      <ul id="menu">
        <?php foreach ($entrees as $entree) : ?>
          <?php $cle = $entree['item']; ?>
          <?php if ($cle === 'news' && !$hasNews) : continue; endif; ?>
          <?php if (!isset($liens[$cle])) : continue; endif; ?>
          <?php $libellePerso = $entree['labels'][$locale->value] ?? ''; ?>
        <?php if ($cle === 'gallery') : ?>
        <li class="sous-menu">
          <?php /* « Galerie » mène à la page mère (revue du 2026-09-24) ; le bouton
                   voisin ouvre le sous-menu des rubriques. */ ?>
          <a href="<?= attr($liens[$cle][0]) ?>"<?php if ($section === $cle) : ?> aria-current="page"<?php endif; ?>><?php if ($libellePerso !== '') : ?><?= e($libellePerso) ?><?php else : ?><?= $t($liens[$cle][1]) ?><?php endif; ?></a>
          <button type="button" class="nav-bouton sous-menu-ouvrir" aria-expanded="true" aria-label="<?= $t('nav.gallery_open') ?>">▾</button>
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
        <?php else : ?>
        <li>
          <a href="<?= attr($liens[$cle][0]) ?>"<?php if ($section === $cle) : ?> aria-current="page"<?php endif; ?>><?php if ($libellePerso !== '') : ?><?= e($libellePerso) ?><?php else : ?><?= $t($liens[$cle][1]) ?><?php endif; ?></a>
        </li>
        <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </nav>

    <?php // Accès au panier, présent sur tout le site. La pastille montre le
          // nombre d'articles ; admin.js la met à jour après un ajout en fetch. ?>
    <?php // Espace client (revue du 2026-09-24) : commandes, factures, newsletter. ?>
    <a class="panier-lien compte-lien" href="<?= attr($url->route('account.index', ['locale' => $locale->value])) ?>"><?= $t('nav.account') ?></a>
    <a class="panier-lien" href="<?= attr($url->route('cart.show', ['locale' => $locale->value])) ?>">
      <?= $t('nav.cart') ?>
      <span class="pastille-panier" data-cart-count<?php if ($cartCount === 0) : ?> hidden<?php endif; ?>><?= e($cartCount) ?></span>
    </a>

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
