<?php

/**
 * Page éditoriale à code fixe (02-front §6).
 *
 * Le corps est du HTML DÉJÀ ASSAINI à l'écriture : rendu par richText(), le seul
 * helper autorisé pour du HTML de confiance.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Editorial\ContentTemplate;

// Sections dans l'ordre du modèle « Page » composé en back-office
// (retours du 2026-09-25) ; chacune est un partiel sections/page/{clef}.
/** @var list<string> $sections */
$sections = is_array($data['sections'] ?? null) ? $data['sections'] : ContentTemplate::default('page')->sections();
?>
<article class="wrap page-editoriale">
  <?php foreach ($sections as $section) : ?>
  <?= $partial('sections/page/' . $section, $data) ?>
  <?php endforeach; ?>
</article>
