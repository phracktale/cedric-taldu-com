<?php

/**
 * Bloc « Carte interactive » (retours du 2026-09-25).
 *
 * Rien d'externe n'est chargé à l'affichage : ni Leaflet (servi par le site),
 * ni les tuiles OpenStreetMap, qui ne partent qu'au clic du visiteur sur
 * « Afficher la carte » (carte.js) — RGPD, CSP et EcoIndex. Sans JavaScript,
 * la liste des lieux reste lisible, chacun avec son lien OpenStreetMap.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Editorial\MapSettings;

$carte = $data['map'] ?? null;
$hauteur = is_string($data['height'] ?? null) ? $data['height'] : 'medium';
$lienOsm = static fn (float $lat, float $lng): string
    => 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=16/' . $lat . '/' . $lng;
?>
<?php if ($carte instanceof MapSettings) : ?>
<div class="bloc bloc-carte bloc-carte--<?= attr($hauteur) ?>" data-carte
     data-leaflet="<?= attr($url->asset('vendor/leaflet/leaflet.js')) ?>"
     data-leaflet-css="<?= attr($url->asset('vendor/leaflet/leaflet.css')) ?>"
     data-leaflet-images="<?= attr($url->path('/assets/vendor/leaflet/images/')) ?>"
     data-config="<?= jsonAttr($carte->toArray()) ?>">
  <div class="bloc-carte-zone" data-carte-zone role="region" aria-label="<?= attr($t('map.label')) ?>"></div>
  <p class="bloc-carte-action">
    <button type="button" class="btn btn-vide" data-carte-afficher hidden><?= $t('map.show') ?></button>
    <span class="bloc-carte-note"><?= $t('map.notice') ?></span>
  </p>
  <?php if ($carte->markers !== []) : ?>
  <ul class="bloc-carte-lieux">
    <?php foreach ($carte->markers as $lieu) : ?>
    <li>
      <strong><?= e($lieu['title']) ?></strong>
      <?php if ($lieu['description'] !== '') : ?> — <?= e($lieu['description']) ?><?php endif; ?>
      <a href="<?= attr($lienOsm($lieu['lat'], $lieu['lng'])) ?>" rel="noopener noreferrer" target="_blank"><?= $t('map.open') ?></a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>
