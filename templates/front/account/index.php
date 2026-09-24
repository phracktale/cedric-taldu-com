<?php

/**
 * Espace client — commandes et newsletter (revue du 2026-09-24).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Repository\PersistedOrder;

/** @var Locale $locale */
$locale = $data['locale'];
$csrf = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<PersistedOrder> $commandes */
$commandes = is_array($data['orders'] ?? null) ? $data['orders'] : [];
/** @var callable(string): string $lienCommande */
$lienCommande = $data['orderUrl'];
$abonne = ($data['subscribed'] ?? false) === true;
?>
<article class="wrap page-editoriale compte">
  <header class="page-tete">
    <h1><?= $t('nav.account') ?></h1>
    <p class="page-langue"><?= e(is_string($data['email'] ?? null) ? $data['email'] : '') ?></p>
  </header>

  <section class="compte-bloc">
    <h2><?= $t('account.orders') ?></h2>
    <?php if ($commandes === []) : ?>
      <p><?= $t('account.no_orders') ?></p>
    <?php else : ?>
    <table class="panier-lignes compte-commandes">
      <thead>
        <tr>
          <th scope="col"><?= $t('account.order') ?></th>
          <th scope="col"><?= $t('account.date') ?></th>
          <th scope="col"><?= $t('account.status') ?></th>
          <th scope="col"><?= $t('checkout.total') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($commandes as $commande) : ?>
        <tr>
          <td><a href="<?= attr($lienCommande($commande->reference)) ?>"><?= e($commande->reference) ?></a></td>
          <td><?= e(dateLong($commande->createdAt, $locale)) ?></td>
          <td><?= e($commande->status->label($locale)) ?></td>
          <td><?= e(money($commande->total, $locale)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="compte-bloc">
    <h2><?= $t('account.newsletter_title') ?></h2>
    <form method="post" action="<?= attr($url->route('account.newsletter', ['locale' => $locale->value])) ?>">
      <input type="hidden" name="_token" value="<?= attr($csrf) ?>">
      <?php if ($abonne) : ?>
        <p><?= $t('account.newsletter_on') ?></p>
        <button type="submit" class="btn btn-vide"><?= $t('account.unsubscribe') ?></button>
      <?php else : ?>
        <p><?= $t('account.newsletter_off') ?></p>
        <input type="hidden" name="abonnement" value="1">
        <button type="submit" class="btn btn-vide"><?= $t('account.subscribe') ?></button>
      <?php endif; ?>
    </form>
  </section>

  <form method="post" action="<?= attr($url->route('account.logout', ['locale' => $locale->value])) ?>" class="compte-bloc">
    <input type="hidden" name="_token" value="<?= attr($csrf) ?>">
    <button type="submit" class="btn btn-vide"><?= $t('account.logout') ?></button>
  </form>
</article>
