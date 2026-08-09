<?php

/**
 * Liste des variantes achetables d'une reproduction (fiche œuvre).
 *
 * Extrait en partial pour être réutilisé par les deux groupes de la fiche œuvre
 * (tirages Fine Art et éditions limitées) sans dupliquer le formulaire d'ajout.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Shop\Product;

/** @var Product $product */
$product = $data['product'];
/** @var Locale $locale */
$locale = $data['locale'];
/** @var string $cartAddUrl */
$cartAddUrl = $data['cartAddUrl'];
/** @var string $csrfToken */
$csrfToken = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
?>
<ul class="variantes">
  <?php foreach ($product->availableVariants() as $variante) : ?>
  <li class="variante">
    <span class="variante-taille"><?= e($variante->label($locale)) ?></span>
    <span class="variante-prix"><?= e(money($variante->price, $locale)) ?></span>
    <form method="post" action="<?= attr($cartAddUrl) ?>" data-cart-add>
      <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">
      <input type="hidden" name="kind" value="reproduction">
      <input type="hidden" name="id" value="<?= attr($variante->id) ?>">
      <button type="submit" class="btn btn-vide"><?= $t('artwork.add_to_cart') ?></button>
      <p class="achat-confirme" role="status" data-cart-confirm hidden>
        <?= $t('cart.added') ?>
        <a href="<?= attr($url->route('cart.show', ['locale' => $locale->value])) ?>"><?= $t('cart.view') ?></a>
      </p>
    </form>
  </li>
  <?php endforeach; ?>
</ul>
