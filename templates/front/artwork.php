<?php

/**
 * Fiche œuvre, en lecture seule (02-front-public §4).
 *
 * Colonne visuelle collante à gauche, colonne d'informations à droite, une
 * seule colonne sous 860 px.
 *
 * La zone d'achat arrive au lot 3 : le statut et le prix qui la conditionnent
 * sont déjà là, et déjà testés.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Media;
use App\Domain\Locale;
use App\Domain\Money;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var Artwork $oeuvre */
$oeuvre = $data['artwork'];
/** @var Category|null $rubrique */
$rubrique = $data['category'];
/** @var list<Artwork> $liees */
$liees = $data['related'];
/** @var array<int, Media> $medias */
$medias = $data['medias'];
/** @var list<App\Domain\Shop\Product> $products */
$products = $data['products'];
/** @var string $cartAddUrl */
$cartAddUrl = $data['cartAddUrl'];
/** @var string $csrfToken */
$csrfToken = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

$media = $medias[$oeuvre->primaryMediaId] ?? null;

?>
<div class="wrap">
  <nav class="fil" aria-label="<?= $t('nav.breadcrumb') ?>">
    <ol>
      <li><a href="<?= attr($url->route('home', ['locale' => $locale->value])) ?>"><?= $t('nav.home') ?></a></li>
      <?php if ($rubrique !== null) : ?>
      <li><a href="<?= attr($url->route('category.show', ['locale' => $locale->value, 'slug' => $rubrique->slug($locale)->value])) ?>"><?= e($rubrique->title($locale)) ?></a></li>
      <?php endif; ?>
      <li><?= e($oeuvre->title($locale)) ?></li>
    </ol>
  </nav>
</div>

<div class="fiche wrap">
  <div class="visuel">
    <?php if ($media instanceof Media) : ?>
      <?php /* Sans JavaScript, le visuel ouvre l'image en pleine taille dans un
               nouvel onglet ; zoom.js intercepte le clic quand il est chargé. */ ?>
      <a
        class="cadre"
        href="<?= attr($url->media($media->derivativeFilename($media->availableWidths()[count($media->availableWidths()) - 1], 'jpg'))) ?>"
        target="_blank"
        rel="noopener"
        data-zoom-src="<?= attr($url->media($media->derivativeFilename($media->availableWidths()[count($media->availableWidths()) - 1], 'jpg'))) ?>"
        data-zoom-alt="<?= attr($media->alt($locale)) ?>"
      ><?= $partial('partials/picture', [
          'media' => $media,
          'locale' => $locale,
          'label' => $oeuvre->title($locale),
          'priority' => true,
          'sizes' => '(max-width: 860px) 100vw, 55vw',
      ]) ?></a>
      <p class="vue-detail"><?= $t('artwork.zoom') ?></p>
    <?php else : ?>
      <div class="cadre"><?= $partial('partials/picture', [
          'media' => null,
          'locale' => $locale,
          'label' => $oeuvre->title($locale),
      ]) ?></div>
    <?php endif; ?>
  </div>

  <div class="infos">
    <?php if ($data['isTranslated'] === false) : ?>
    <p class="repli-langue">This text is only available in French.</p>
    <?php endif; ?>

    <?php if ($oeuvre->translations->for($locale)->eyebrow !== null) : ?>
    <p class="eyebrow"><?= e($oeuvre->translations->for($locale)->eyebrow) ?></p>
    <?php endif; ?>

    <h1><?= e($oeuvre->title($locale)) ?></h1>

    <?php if ($oeuvre->specifications($locale) !== '') : ?>
    <p class="specs"><?= e($oeuvre->specifications($locale)) ?></p>
    <?php endif; ?>

    <?php if ($oeuvre->price instanceof Money) : ?>
    <p class="prix"><?= money($oeuvre->price, $locale) ?></p>
    <?php endif; ?>

    <?php if ($oeuvre->status->hasBadge()) : ?>
    <p class="dispo<?= $oeuvre->isPurchasable() ? '' : ' vendue' ?>"><?= e($oeuvre->status->label($locale)) ?></p>
    <?php endif; ?>

    <div class="texte">
      <?php if ($oeuvre->translations->for($locale)->description !== null) : ?>
        <?php /* HTML assaini À L'ÉCRITURE en back-office : la lecture affiche
                 (06-securite §2). Le re-échapper afficherait le balisage. */ ?>
        <p class="forte"><?= e(trim(strip_tags($oeuvre->translations->for($locale)->description))) ?></p>
      <?php endif; ?>
      <?php if ($oeuvre->translations->for($locale)->detail !== null) : ?>
        <p><?= e(trim(strip_tags($oeuvre->translations->for($locale)->detail))) ?></p>
      <?php endif; ?>
    </div>

    <?php // Zone d'achat de l'original (02-front-public §4.6). Le bouton
          // n'existe que si l'œuvre est disponible ET a un prix : isPurchasable()
          // porte les deux conditions. ?>
    <?php if ($oeuvre->isPurchasable()) : ?>
    <div class="achat">
      <form method="post" action="<?= attr($cartAddUrl) ?>" data-cart-add>
        <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">
        <input type="hidden" name="kind" value="original">
        <input type="hidden" name="id" value="<?= attr($oeuvre->id) ?>">
        <button type="submit" class="btn btn-plein">
          <?= $t('artwork.acquire') ?>
        </button>
      </form>
    </div>
    <?php endif; ?>

    <?php // « Poser une question » (02-front §4.6) : sans JavaScript, un simple
          // lien vers le formulaire de contact pré-rempli du contexte de l'œuvre.
          // Un module JS pourra plus tard l'ouvrir en place. ?>
    <p class="poser-question">
      <a class="btn btn-vide"
         href="<?= attr($url->route('contact.form', ['locale' => $locale->value]) . '?oeuvre=' . rawurlencode($oeuvre->slug($locale)->value)) ?>">
        <?= $t('artwork.ask_question') ?>
      </a>
    </p>

    <?php if ($products !== []) : ?>
    <section class="reproductions">
      <h2><?= $t('artwork.prints') ?></h2>
      <?php foreach ($products as $product) : ?>
        <?php if (!$product->isPurchasable()) {
            continue;
        } ?>
        <div class="reproduction">
          <h3><?= e($product->title) ?></h3>
          <?php if ($product->editionsRemaining() !== null) : ?>
          <p class="edition">
            <?= $t('artwork.editions_remaining', ['count' => $product->editionsRemaining()]) ?>
          </p>
          <?php endif; ?>
          <ul class="variantes">
            <?php foreach ($product->availableVariants() as $variante) : ?>
            <li class="variante">
              <span class="variante-taille"><?= e($variante->label($locale)) ?></span>
              <span class="variante-prix"><?= e(money($variante->price, $locale)) ?></span>
              <form method="post" action="<?= attr($cartAddUrl) ?>" data-cart-add>
                <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">
                <input type="hidden" name="kind" value="reproduction">
                <input type="hidden" name="id" value="<?= attr($variante->id) ?>">
                <button type="submit" class="btn btn-vide">
                  <?= $t('artwork.add_to_cart') ?>
                </button>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($liees !== []) : ?>
<section class="liees">
  <div class="wrap">
    <h2><?= $t('artwork.related') ?></h2>
    <div class="liees-grid">
      <?php foreach ($liees as $liee) : ?>
        <?= $partial('partials/artwork-card', [
            'artwork' => $liee,
            'locale' => $locale,
            'media' => $medias[$liee->primaryMediaId] ?? null,
            'class' => 'liee',
        ]) ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
