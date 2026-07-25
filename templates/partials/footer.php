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

?>
<?php
$liensLegaux = [
    ['route' => 'page.legal', 'cle' => 'footer.legal'],
    ['route' => 'page.privacy', 'cle' => 'footer.privacy'],
    ['route' => 'page.terms', 'cle' => 'footer.terms'],
    ['route' => 'contact.form', 'cle' => 'footer.contact'],
];
?>
<footer>
  <div class="foot">
    <p>© 2025–<?= e($annee) ?> Cédric Taldu — <?= $t('footer.role') ?></p>

    <nav class="foot-legal" aria-label="<?= $t('footer.legal_label') ?>">
      <?php foreach ($liensLegaux as $lien) : ?>
        <a href="<?= attr($url->route($lien['route'], ['locale' => $locale->value])) ?>"><?= $t($lien['cle']) ?></a>
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
