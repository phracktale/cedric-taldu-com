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
$estFr = $locale === Locale::Fr;
$liensLegaux = [
    ['route' => 'page.legal', 'libelle' => $estFr ? 'Mentions légales' : 'Legal notice'],
    ['route' => 'page.privacy', 'libelle' => $estFr ? 'Confidentialité' : 'Privacy'],
    ['route' => 'page.terms', 'libelle' => $estFr ? 'CGV' : 'Terms'],
    ['route' => 'contact.form', 'libelle' => 'Contact'],
];
?>
<footer>
  <div class="foot">
    <p>© 2025–<?= e($annee) ?> Cédric Taldu — <?= e($locale === Locale::Fr ? 'Artiste plasticien, Amiens, Hauts-de-France' : 'Visual artist, Amiens, France') ?></p>

    <nav class="foot-legal" aria-label="<?= attr($estFr ? 'Informations légales' : 'Legal') ?>">
      <?php foreach ($liensLegaux as $lien) : ?>
        <a href="<?= attr($url->route($lien['route'], ['locale' => $locale->value])) ?>"><?= e($lien['libelle']) ?></a>
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
