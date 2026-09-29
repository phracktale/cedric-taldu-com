<?php

/**
 * Section « Carte » du modèle « Contact » (retour client du 2026-09-29) : la
 * carte interactive de Modules › Carte interactive (OpenStreetMap, chargée au
 * clic du visiteur).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

?>
<?= $partial('partials/carte', ['map' => $data['map'] ?? null, 'height' => 'medium', 'locale' => $data['locale']]) ?>
