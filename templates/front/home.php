<?php

/**
 * Accueil — huit modules ordonnés (02-front-public §2).
 *
 * Les textes viennent de `settings`, les rubriques et les œuvres de la base.
 * Le module « Galeries » est dynamique : ajouter une rubrique en back-office
 * fait apparaître une carte de plus, sans toucher au code.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Category;
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
/** @var string $newsIndexUrl */
$newsIndexUrl = is_string($data['newsIndexUrl'] ?? null) ? $data['newsIndexUrl'] : '';

$moisFr = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$moisEn = [1 => 'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'];

$formatDate = static function (?\DateTimeImmutable $date) use ($locale, $moisFr, $moisEn): string {
    if ($date === null) {
        return '';
    }

    $estFr = $locale === Locale::Fr;
    $mois = ($estFr ? $moisFr : $moisEn)[(int) $date->format('n')];

    return $estFr
        ? $date->format('j') . ' ' . $mois . ' ' . $date->format('Y')
        : $mois . ' ' . $date->format('j') . ', ' . $date->format('Y');
};

?>

<?php /* 1 — HERO : le H1 SEO, la baseline, deux portes d'entrée. */ ?>
<section class="hero wrap">
  <?php if ($texte($hero, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($hero, 'eyebrow')) ?></p><?php endif; ?>
  <h1><?= e($texte($hero, 'title') ?? 'Cédric Taldu') ?></h1>
  <?php if ($texte($hero, 'baseline') !== null) : ?><p class="baseline"><?= e($texte($hero, 'baseline')) ?></p><?php endif; ?>
  <?php if ($rubriques !== []) : ?>
  <div class="cta-row">
    <a class="btn btn-plein" href="#galeries"><?= e($texte($hero, 'cta') ?? ($locale === Locale::Fr ? 'Voir les œuvres' : 'View the works')) ?></a>
  </div>
  <?php endif; ?>
</section>

<?php /* 2 — VITRINE : trois œuvres, celle du centre plus haute. */ ?>
<?php if ($vitrine !== []) : ?>
<section class="vitrine wrap" aria-label="<?= attr($locale === Locale::Fr ? 'Œuvres en vitrine' : 'Featured works') ?>">
  <div class="vitrine-grid">
    <?php foreach ($vitrine as $rang => $oeuvre) : ?>
      <?= $partial('partials/artwork-card', [
          'artwork' => $oeuvre,
          'locale' => $locale,
          'media' => $medias[$oeuvre->primaryMediaId] ?? null,
          'class' => $rang === 1 ? 'large' : '',
          'priority' => $rang < 2,
      ]) ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* 3 — TRIPTYQUE : les trois notions du livret. */ ?>
<?php if ($cellules !== []) : ?>
<section class="triptyque">
  <div class="wrap">
    <?php if ($texte($triptych, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($triptych, 'eyebrow')) ?></p><?php endif; ?>
    <h2><?= e($texte($triptych, 'title') ?? '') ?></h2>
    <?php if ($texte($triptych, 'intro') !== null) : ?><p class="intro"><?= e($texte($triptych, 'intro')) ?></p><?php endif; ?>
    <div class="tri-grid">
      <?php foreach ($cellules as $cellule) : ?>
      <div class="tri-cell">
        <h3><?= e($texte($cellule, 'title') ?? '') ?></h3>
        <p><?= e($texte($cellule, 'text') ?? '') ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php /* 4 — GALERIES : une carte par rubrique publiée, alimentée par la base. */ ?>
<?php if ($rubriques !== []) : ?>
<section class="galeries wrap" id="galeries">
  <p class="eyebrow"><?= e($locale === Locale::Fr ? 'Galeries' : 'Galleries') ?></p>
  <h2><?= e($locale === Locale::Fr ? 'Le travail, par technique' : 'The work, by medium') ?></h2>
  <?php /* Au-delà de deux rubriques, la grille passe en auto-fit : ajouter une
           rubrique en back-office ne demande aucune retouche. */ ?>
  <div class="gal-grid<?php if (count($rubriques) > 2) : ?> auto<?php endif; ?>">
    <?php foreach ($rubriques as $rubrique) : ?>
    <a class="gal-card" href="<?= attr($url->route('category.show', ['locale' => $locale->value, 'slug' => $rubrique->slug($locale)->value])) ?>">
      <h3><?= e($rubrique->title($locale)) ?></h3>
      <?php if ($rubrique->description($locale) !== null) : ?>
      <p><?= e(mb_substr(trim(strip_tags($rubrique->description($locale))), 0, 220)) ?></p>
      <?php endif; ?>
      <span class="lien"><?= e(($locale === Locale::Fr ? 'Voir ' : 'View ') . mb_strtolower($rubrique->title($locale))) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* 5 — BOUTIQUE : bande contrastée. */ ?>
<?php if ($texte($shop, 'title') !== null) : ?>
<section class="boutique" id="boutique">
  <div class="wrap">
    <?php if ($texte($shop, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($shop, 'eyebrow')) ?></p><?php endif; ?>
    <h2><?= e($texte($shop, 'title')) ?></h2>
    <hr class="stipple">
    <?php if ($texte($shop, 'text') !== null) : ?><p><?= e($texte($shop, 'text')) ?></p><?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php /* 6 — ATELIER : portrait, bio courte. */ ?>
<?php if ($texte($studio, 'title') !== null) : ?>
<section class="atelier wrap" id="atelier">
  <div class="atelier-grid">
    <div class="portrait"><span><?= e($locale === Locale::Fr ? 'Portrait d’atelier' : 'Studio portrait') ?></span></div>
    <div>
      <?php if ($texte($studio, 'eyebrow') !== null) : ?><p class="eyebrow"><?= e($texte($studio, 'eyebrow')) ?></p><?php endif; ?>
      <h2><?= e($texte($studio, 'title')) ?></h2>
      <?php if ($texte($studio, 'lead') !== null) : ?><p class="forte"><?= e($texte($studio, 'lead')) ?></p><?php endif; ?>
      <?php foreach ($paragraphes as $paragraphe) : ?>
        <?php if (is_string($paragraphe) && $paragraphe !== '') : ?><p><?= e($paragraphe) ?></p><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php /* 7 — ACTUS : les trois derniers articles publiés (02-front §2, module 7). */ ?>
<?php if ($recentPosts !== []) : ?>
<section class="actus" id="actus">
  <div class="wrap">
    <p class="eyebrow"><?= e($locale === Locale::Fr ? 'Actualités' : 'News') ?></p>
    <h2><?= e($texte($news, 'title') ?? ($locale === Locale::Fr ? 'Expositions et travail en cours' : 'Exhibitions and work in progress')) ?></h2>
    <div class="actu-liste">
      <?php foreach ($recentPosts as $post) : ?>
        <?php $dateAffichee = $post->eventDate ?? $post->publishedAt; ?>
      <article class="actu">
        <?php if ($dateAffichee !== null) : ?>
        <time datetime="<?= attr($dateAffichee->format('Y-m-d')) ?>"><?= e($formatDate($dateAffichee)) ?></time>
        <?php endif; ?>
        <h3><a href="<?= attr($articleUrl($post)) ?>"><?= e($post->title($locale)) ?></a></h3>
        <?php if ($post->isEvent() && $post->eventPlace !== null) : ?>
        <p class="lieu"><?= e($post->eventPlace) ?></p>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="actus-plus">
      <a href="<?= attr($newsIndexUrl) ?>"><?= e($locale === Locale::Fr ? 'Toutes les actus' : 'All news') ?></a>
    </p>
  </div>
</section>
<?php endif; ?>

<?php /* 8 — CONTACT. */ ?>
<?php if ($texte($contact, 'title') !== null) : ?>
<section class="contact wrap" id="contact">
  <p class="eyebrow"><?= e($texte($contact, 'eyebrow') ?? ($locale === Locale::Fr ? 'Contact' : 'Contact')) ?></p>
  <h2><?= e($texte($contact, 'title')) ?></h2>
  <?php if ($texte($contact, 'text') !== null) : ?><p><?= e($texte($contact, 'text')) ?></p><?php endif; ?>
</section>
<?php endif; ?>
