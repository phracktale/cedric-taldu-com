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

use App\Domain\Catalog\Media;
use App\Domain\Editorial\Block;
use App\Domain\Locale;

/** @var list<Block> $blocks */
$blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];
/** @var Locale $locale */
$locale = $data['locale'];
/** @var array<int, Media> $medias médias des blocs image, chargés par le contrôleur */
$medias = is_array($data['medias'] ?? null) ? $data['medias'] : [];

/** Ramène une valeur à une liste blanche ; le premier élément est le défaut. */
$parmi = static fn (string $valeur, array $autorises): string
    => in_array($valeur, $autorises, true) ? $valeur : $autorises[0];

/**
 * Une URL sûre pour un href : http(s), mailto ou lien interne ; sinon inerte.
 * Un lien interne reçoit le préfixe de chemin (aucune URL en dur, CLAUDE.md) ;
 * « //hote » mènerait hors du site et reste inerte.
 */
$lien = static function (string $u) use ($url): string {
    if (preg_match('#^/(?!/)#', $u) === 1) {
        return $url->path($u);
    }

    return preg_match('#^(https?:|mailto:)#i', $u) === 1 ? $u : '#';
};

/** Alignement du bouton : valeur du catalogue → classe CTA du site. */
$alignements = ['center' => 'centre', 'left' => 'gauche', 'right' => 'droite'];

/** Prop de design ramenée aux options du catalogue (la première est le défaut). */
$choix = static fn (Block $b, string $prop): string => $parmi(
    $b->text($prop),
    App\Domain\Editorial\BlockCatalog::definition($b->type)['schema'][$prop]['options'] ?? [''],
);
?>
<?php foreach ($blocks as $block) : ?>
  <?php if ($block->type === 'text') : ?>
    <div class="bloc bloc-texte"><?= richText($block->text('content')) ?></div>

  <?php elseif ($block->type === 'heading') : ?>
    <?php $niveau = $parmi($block->text('level', '2'), ['2', '3', '4']); ?>
    <h<?= e($niveau) ?> class="bloc bloc-titre"><?= e($block->text('text')) ?></h<?= e($niveau) ?>>

  <?php elseif ($block->type === 'image') : ?>
    <?php $legende = $block->text('caption'); ?>
    <?php $media = $medias[(int) $block->text('media')] ?? null; ?>
    <?php if ($media instanceof Media) : ?>
    <figure class="bloc bloc-image">
      <?= $partial('partials/picture', [
          'media' => $media,
          'locale' => $locale,
          'sizes' => '(max-width: 900px) 100vw, 72rem',
          'label' => $block->text('alt') !== '' ? $block->text('alt') : $legende,
      ]) ?>
      <?php if ($legende !== '') : ?><figcaption><?= e($legende) ?></figcaption><?php endif; ?>
    </figure>
    <?php elseif ($block->text('src') !== '') : ?>
    <figure class="bloc bloc-image">
      <img src="<?= attr($lien($block->text('src'))) ?>" alt="<?= attr($block->text('alt')) ?>" loading="lazy">
      <?php if ($legende !== '') : ?><figcaption><?= e($legende) ?></figcaption><?php endif; ?>
    </figure>
    <?php endif; ?>

  <?php elseif ($block->type === 'hero') : ?>
    <?php $image = $medias[(int) $block->text('media')] ?? null; ?>
    <?php $niveau = $choix($block, 'titleLevel'); ?>
    <section class="bloc bloc-hero bloc-hero--<?= e($choix($block, 'height')) ?> bloc-align--<?= e($choix($block, 'align')) ?> bloc-ton--<?= e($choix($block, 'tone')) ?> bloc-voile--<?= e($image instanceof Media ? $choix($block, 'overlay') : 'none') ?>">
      <?= $partial('partials/bloc-fond', ['media' => $image, 'locale' => $locale, 'label' => $block->text('title')]) ?>
      <div class="bloc-hero-contenu">
        <?php if ($block->text('title') !== '') : ?>
        <h<?= e($niveau) ?> class="bloc-hero-titre"><?= e($block->text('title')) ?></h<?= e($niveau) ?>>
        <?php endif; ?>
        <?php if ($block->text('text') !== '') : ?>
        <div class="bloc-hero-texte"><?= richText($block->text('text')) ?></div>
        <?php endif; ?>
        <?php if ($block->text('buttonLabel') !== '') : ?>
        <p class="bloc-hero-action"><a class="btn btn-plein" href="<?= attr($lien($block->text('buttonUrl'))) ?>"><?= e($block->text('buttonLabel')) ?></a></p>
        <?php endif; ?>
      </div>
    </section>

  <?php elseif ($block->type === 'media-text') : ?>
    <?php $image = $medias[(int) $block->text('media')] ?? null; ?>
    <div class="bloc bloc-media-texte bloc-media-texte--<?= e($choix($block, 'position')) ?> bloc-ratio--<?= e($choix($block, 'ratio')) ?>">
      <?php if ($image instanceof Media) : ?>
      <figure class="bloc-media-texte-image">
        <?= $partial('partials/picture', ['media' => $image, 'locale' => $locale, 'sizes' => '(max-width: 700px) 100vw, 50vw']) ?>
      </figure>
      <?php endif; ?>
      <div class="bloc-media-texte-texte"><?= richText($block->text('content')) ?></div>
    </div>

  <?php elseif ($block->type === 'map') : ?>
    <?= $partial('partials/carte', ['map' => $data['map'] ?? null, 'height' => $choix($block, 'height'), 'locale' => $locale]) ?>

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
    <?php $alignement = $alignements[$parmi($block->text('align', 'center'), ['center', 'left', 'right'])]; ?>
    <p class="bloc bloc-cta cta-row cta-row--<?= e($alignement) ?>">
      <a class="btn <?= e($classe) ?>" href="<?= attr($lien($block->text('url'))) ?>"><?= e($block->text('label')) ?></a>
    </p>

  <?php elseif ($block->type === 'columns') : ?>
    <?php $nb = $parmi($block->text('count', '2'), ['2', '3', '4']); ?>
    <?php $gap = $parmi($block->text('gap', 'md'), ['sm', 'md', 'lg']); ?>
    <div class="bloc bloc-colonnes bloc-colonnes--<?= e($nb) ?> bloc-gap--<?= e($gap) ?> bloc-ratio--<?= e($choix($block, 'ratio')) ?>">
      <?= $partial('partials/blocks', ['blocks' => $block->children, 'locale' => $locale, 'medias' => $medias, 'map' => $data['map'] ?? null]) ?>
    </div>

  <?php elseif ($block->type === 'section') : ?>
    <?php $pad = $parmi($block->text('padding', 'md'), ['none', 'sm', 'md', 'lg', 'xl']); ?>
    <?php $largeur = $parmi($block->text('maxWidth', 'prose'), ['prose', 'content', 'wide', 'full']); ?>
    <?php $image = $medias[(int) $block->text('backgroundMedia')] ?? null; ?>
    <section class="bloc bloc-section bloc-pad--<?= e($pad) ?> bloc-max--<?= e($largeur) ?> bloc-couleur--<?= e($choix($block, 'background')) ?> bloc-ton--<?= e($choix($block, 'tone')) ?> bloc-align--<?= e($choix($block, 'align')) ?> bloc-voile--<?= e($image instanceof Media ? $choix($block, 'overlay') : 'none') ?>">
      <?= $partial('partials/bloc-fond', ['media' => $image, 'locale' => $locale, 'label' => '']) ?>
      <div class="bloc-section-contenu">
        <?= $partial('partials/blocks', ['blocks' => $block->children, 'locale' => $locale, 'medias' => $medias, 'map' => $data['map'] ?? null]) ?>
      </div>
    </section>
  <?php endif; ?>
  <?php // Un type inconnu ne correspond à aucune branche : ignoré silencieusement. ?>
<?php endforeach; ?>
