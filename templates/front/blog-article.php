<?php

/**
 * Article d'actus (02-front §6).
 *
 * Le corps est du HTML DÉJÀ ASSAINI à l'écriture (06-securite §2) : il est
 * rendu par richText(), le seul helper autorisé pour du HTML de confiance.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Editorial\ContentBlock;
use App\Domain\Editorial\ContentTemplate;

// Sections dans l'ordre du modèle « Actualité » composé en back-office
// (retours du 2026-09-25) ; chacune est un partiel sections/post/{clef}.
/** @var list<string> $sections */
$sections = is_array($data['sections'] ?? null) ? $data['sections'] : ContentTemplate::default('post')->sections();
?>
<article class="wrap article">
  <?php foreach ($sections as $section) : ?>
  <?php $bloc = ContentBlock::idFromKey($section); ?>
  <?php if ($bloc !== null) : ?>
  <?= $partial('partials/content-block', ['placed' => $data['contentBlocks'][$bloc] ?? null, 'locale' => $data['locale'], 'map' => $data['map'] ?? null]) ?>
  <?php else : ?>
  <?= $partial('sections/post/' . $section, $data) ?>
  <?php endif; ?>
  <?php endforeach; ?>
</article>
