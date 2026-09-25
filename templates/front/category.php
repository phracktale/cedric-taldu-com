<?php

/**
 * Page rubrique (02-front-public §3).
 *
 * Le filtre de série est un LIEN, rendu côté serveur : l'URL reste partageable
 * et la page fonctionne sans JavaScript.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Editorial\ContentBlock;
use App\Domain\Editorial\ContentTemplate;

// Sections dans l'ordre du modèle « Galerie » composé en back-office
// (retours du 2026-09-25) ; chacune est un partiel sections/category/{clef}.
/** @var list<string> $sections */
$sections = is_array($data['sections'] ?? null) ? $data['sections'] : ContentTemplate::default('category')->sections();
?>
<?php foreach ($sections as $section) : ?>
<?php $bloc = ContentBlock::idFromKey($section); ?>
<?php if ($bloc !== null) : ?>
<?= $partial('partials/content-block', ['placed' => $data['contentBlocks'][$bloc] ?? null, 'locale' => $data['locale']]) ?>
<?php else : ?>
<?= $partial('sections/category/' . $section, $data) ?>
<?php endif; ?>
<?php endforeach; ?>
