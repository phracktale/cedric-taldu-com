<?php

/**
 * Confirmation d'un ajout au panier (retours du 2026-09-25, point 7).
 *
 * Atteinte quand un ajout arrive sans jeton valide — le cas d'une page
 * statique consultée sans JavaScript. Le formulaire reposte l'ajout avec un
 * jeton frais ; le prix affiché vient du catalogue, jamais de la requête.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Shop\ValuedLine;

/** @var Locale $locale */
$locale = $data['locale'];
/** @var ValuedLine $ligne */
$ligne = $data['line'];
/** @var string $addUrl */
$addUrl = $data['addUrl'];
/** @var string $panierUrl */
$panierUrl = $data['panierUrl'];
/** @var string $csrfToken */
$csrfToken = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
?>
<main class="panier" id="contenu">
  <div class="wrap">
    <h1><?= $t('cart.confirm_title') ?></h1>
    <p><?= $t('cart.confirm_intro') ?></p>

    <p class="panier-article">
      <strong><?= e($ligne->item->label) ?></strong>
      — <?= e(money($ligne->total, $locale)) ?>
    </p>

    <form method="post" action="<?= attr($addUrl) ?>" class="achat">
      <input type="hidden" name="_token" value="<?= attr($csrfToken) ?>">
      <input type="hidden" name="kind" value="<?= attr($ligne->item->kind->value) ?>">
      <input type="hidden" name="id" value="<?= attr($ligne->item->targetId) ?>">
      <input type="hidden" name="quantite" value="<?= attr($ligne->quantity) ?>">
      <button type="submit" class="btn btn-plein"><?= $t('cart.confirm_button') ?></button>
    </form>

    <p><a href="<?= attr($panierUrl) ?>"><?= $t('cart.view') ?></a></p>
  </div>
</main>
