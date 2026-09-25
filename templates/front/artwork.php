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

use App\Domain\Editorial\ContentBlock;
use App\Domain\Editorial\ContentTemplate;

// Sections dans l'ordre du modèle « Œuvre » composé en back-office
// (retours du 2026-09-25) ; chacune est un partiel sections/artwork/{clef}.
/** @var list<string> $sections */
$sections = is_array($data['sections'] ?? null) ? $data['sections'] : ContentTemplate::default('artwork')->sections();
?>
<?php foreach ($sections as $section) : ?>
<?php $bloc = ContentBlock::idFromKey($section); ?>
<?php if ($bloc !== null) : ?>
<?= $partial('partials/content-block', ['placed' => $data['contentBlocks'][$bloc] ?? null, 'locale' => $data['locale'], 'map' => $data['map'] ?? null]) ?>
<?php else : ?>
<?= $partial('sections/artwork/' . $section, $data) ?>
<?php endif; ?>
<?php endforeach; ?>
