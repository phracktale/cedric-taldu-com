<?php

/**
 * Rendu d'une liste de blocs éditoriaux (format editor-core / FatPlant).
 *
 * Chaque type connu a son gabarit ; un type inconnu est ignoré (le document
 * vient de la base, une valeur inattendue ne doit pas casser la page). Les
 * conteneurs (colonnes, section) rendent leurs enfants en se rappelant eux-mêmes.
 *
 * Sécurité : le HTML riche du bloc texte est assaini À L'ÉCRITURE et rendu par
 * richText() ; tout le reste passe par e()/attr(). Les valeurs qui pilotent une
 * balise, une classe ou une URL sont ramenées à une LISTE BLANCHE — jamais
 * écrites telles quelles.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Editorial\Block;
use App\Domain\Locale;

/** @var list<Block> $blocks */
$blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];
/** @var Locale $locale */
$locale = $data['locale'];

/** Ramène une valeur à une liste blanche ; le premier élément est le défaut. */
$parmi = static fn (string $valeur, array $autorises): string
    => in_array($valeur, $autorises, true) ? $valeur : $autorises[0];

/** Une URL sûre pour un href : http(s), mailto ou lien interne ; sinon inerte. */
$lien = static fn (string $u): string
    => preg_match('#^(https?:|mailto:|/)#i', $u) === 1 ? $u : '#';
?>
<?php foreach ($blocks as $block) : ?>
  <?php if ($block->type === 'text') : ?>
    <div class="bloc bloc-texte"><?= richText($block->text('content')) ?></div>

  <?php elseif ($block->type === 'heading') : ?>
    <?php $niveau = $parmi($block->text('level', '2'), ['2', '3', '4']); ?>
    <h<?= e($niveau) ?> class="bloc bloc-titre"><?= e($block->text('text')) ?></h<?= e($niveau) ?>>

  <?php elseif ($block->type === 'image') : ?>
    <?php $legende = $block->text('caption'); ?>
    <figure class="bloc bloc-image">
      <img src="<?= attr($lien($block->text('src'))) ?>" alt="<?= attr($block->text('alt')) ?>" loading="lazy">
      <?php if ($legende !== '') : ?><figcaption><?= e($legende) ?></figcaption><?php endif; ?>
    </figure>

  <?php elseif ($block->type === 'quote') : ?>
    <?php $auteur = $block->text('author'); ?>
    <?php $source = $block->text('source'); ?>
    <blockquote class="bloc bloc-citation">
      <p><?= e($block->text('text')) ?></p>
      <?php if ($auteur !== '' || $source !== '') : ?>
      <cite><?= e(trim($auteur . ' — ' . $source, ' —')) ?></cite>
      <?php endif; ?>
    </blockquote>

  <?php elseif ($block->type === 'divider') : ?>
    <?php $style = $parmi($block->text('style', 'line'), ['line', 'dots', 'space']); ?>
    <hr class="bloc bloc-separateur bloc-separateur--<?= e($style) ?>">

  <?php elseif ($block->type === 'button') : ?>
    <?php $variante = $parmi($block->text('variant', 'primary'), ['primary', 'secondary', 'outline']); ?>
    <?php $classe = $variante === 'primary' ? 'btn-plein' : 'btn-vide'; ?>
    <p class="bloc bloc-cta">
      <a class="btn <?= e($classe) ?>" href="<?= attr($lien($block->text('url'))) ?>"><?= e($block->text('label')) ?></a>
    </p>

  <?php elseif ($block->type === 'columns') : ?>
    <?php $nb = $parmi($block->text('count', '2'), ['2', '3', '4']); ?>
    <?php $gap = $parmi($block->text('gap', 'md'), ['sm', 'md', 'lg']); ?>
    <div class="bloc bloc-colonnes bloc-colonnes--<?= e($nb) ?> bloc-gap--<?= e($gap) ?>">
      <?= $partial('partials/blocks', ['blocks' => $block->children, 'locale' => $locale]) ?>
    </div>

  <?php elseif ($block->type === 'section') : ?>
    <?php $pad = $parmi($block->text('padding', 'md'), ['none', 'sm', 'md', 'lg', 'xl']); ?>
    <?php $largeur = $parmi($block->text('maxWidth', 'prose'), ['prose', 'content', 'wide', 'full']); ?>
    <section class="bloc bloc-section bloc-pad--<?= e($pad) ?> bloc-max--<?= e($largeur) ?>">
      <?= $partial('partials/blocks', ['blocks' => $block->children, 'locale' => $locale]) ?>
    </section>
  <?php endif; ?>
  <?php // Un type inconnu ne correspond à aucune branche : ignoré silencieusement. ?>
<?php endforeach; ?>
