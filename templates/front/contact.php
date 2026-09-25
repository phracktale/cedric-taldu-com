<?php

/**
 * Formulaire de contact, général ou rattaché à une œuvre (02-front §6).
 *
 * Le champ appât `site_web` est masqué hors écran (jamais display:none seul),
 * aria-hidden + tabindex=-1 (06-securite §6.1). Le champ caché d'horodatage
 * porte la signature émise à l'affichage (§6.2). Aucun gestionnaire inline.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Editorial\ContentBlock;
use App\Domain\Editorial\ContentTemplate;

// Sections dans l'ordre du modèle « Contact » composé en back-office
// (retours du 2026-09-25) ; chacune est un partiel sections/contact/{clef}.
/** @var list<string> $sections */
$sections = is_array($data['sections'] ?? null) ? $data['sections'] : ContentTemplate::default('contact')->sections();
?>
<div class="wrap contact">
  <?php foreach ($sections as $section) : ?>
  <?php $bloc = ContentBlock::idFromKey($section); ?>
  <?php if ($bloc !== null) : ?>
  <?= $partial('partials/content-block', ['placed' => $data['contentBlocks'][$bloc] ?? null, 'locale' => $data['locale']]) ?>
  <?php else : ?>
  <?= $partial('sections/contact/' . $section, $data) ?>
  <?php endif; ?>
  <?php endforeach; ?>
</div>
