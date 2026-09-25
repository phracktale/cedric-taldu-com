<?php

/**
 * Pied de page.
 *
 * Le sélecteur de langue pointe vers l'URL ÉQUIVALENTE dans l'autre langue,
 * calculée par le contrôleur (05-i18n-seo §2), et non vers l'accueil : renvoyer
 * un visiteur à l'accueil parce qu'il change de langue lui fait perdre sa page.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Locale;

/** @var Locale $locale */
$locale = $data['locale'];

/** @var array<string, string> $langues URL équivalente par code de langue */
$langues = is_array($data['localeSwitch'] ?? null) ? $data['localeSwitch'] : [];

$annee = is_string($data['year'] ?? null) ? $data['year'] : '2026';

// Identité du site (Paramètres › Global), partagée par View::share.
$site = ($data['site'] ?? null) instanceof App\Domain\Editorial\SiteIdentity
    ? $data['site']
    : App\Domain\Editorial\SiteIdentity::fromStored([]);
?>
<?php
// Menu du pied de page composé par glisser-déposer (retours du 2026-09-25).
/** @var list<array{href: string, label: string}> $liensPied */
$liensPied = is_array($data['footerItems'] ?? null) ? $data['footerItems'] : [];
?>
<footer>
  <div class="foot">
    <p>© <?php if ($site->since < (int) $annee) : ?><?= e($site->since) ?>–<?php endif; ?><?= e($annee) ?> <?= e($site->name) ?><?php if ($site->role($locale) !== '') : ?> — <?= e($site->role($locale)) ?><?php endif; ?></p>

    <?php if ($site->socialLinks() !== []) : ?>
    <p class="foot-reseaux">
      <?php foreach ($site->socialLinks() as $reseau) : ?>
        <a href="<?= attr($reseau['url']) ?>" rel="me noopener" target="_blank"><?= e($reseau['label']) ?></a>
      <?php endforeach; ?>
    </p>
    <?php endif; ?>

    <nav class="foot-legal" aria-label="<?= $t('footer.legal_label') ?>">
      <?php foreach ($liensPied as $lien) : ?>
        <a href="<?= attr($lien['href']) ?>"><?= e($lien['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <p>
      <?php foreach (Locale::cases() as $autre) : ?>
        <?php if ($autre === $locale) : ?>
          <span aria-current="true"><?= e($autre->nativeName()) ?></span>
        <?php elseif (isset($langues[$autre->value])) : ?>
          <a href="<?= attr($langues[$autre->value]) ?>" hreflang="<?= attr($autre->value) ?>"><?= e($autre->nativeName()) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </p>
  </div>
</footer>
