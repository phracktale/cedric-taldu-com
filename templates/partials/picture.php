<?php

/**
 * Visuel d'une œuvre.
 *
 * Remplace les blocs `.dessin` factices des maquettes par une vraie balise
 * `<picture>` : AVIF, puis WebP, puis JPEG de repli, sur cinq largeurs.
 * L'ordre des sources compte — le navigateur retient la première qu'il comprend.
 *
 * `width` et `height` sont toujours présents et l'`aspect-ratio` est posé :
 * c'est ce qui tient l'objectif de CLS < 0,05 (02-front-public §7).
 *
 * La construction des `srcset` a lieu ICI et non dans `Media` : elle a besoin du
 * générateur d'URL, donc du préfixe de chemin, et le domaine ne connaît aucune
 * I/O ni aucun service (src/CLAUDE.md).
 *
 * Sans média, la trame pointillée du design system occupe la place. Elle n'est
 * pas un pis-aller : c'est le motif du site, et elle rend le manque lisible.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Catalog\Media;
use App\Domain\Locale;

$media = $data['media'] ?? null;
/** @var Locale $locale */
$locale = $data['locale'];
$sizes = is_string($data['sizes'] ?? null) ? $data['sizes'] : '(max-width: 900px) 100vw, 33vw';
$prioritaire = ($data['priority'] ?? false) === true;
$etiquette = is_string($data['label'] ?? null) ? $data['label'] : '';

/**
 * @param Media $media
 */
$srcset = static function (Media $media, string $format) use ($url): string {
    $sources = [];

    foreach ($media->availableWidths() as $largeur) {
        $sources[] = $url->media($media->derivativeFilename($largeur, $format)) . ' ' . $largeur . 'w';
    }

    return implode(', ', $sources);
};

?>
<div class="dessin"<?php if ($media instanceof Media) : ?> style="aspect-ratio: <?= attr($media->aspectRatio()) ?>"<?php endif; ?>>
<?php if ($media instanceof Media) : ?>
  <picture>
    <source type="image/webp" srcset="<?= attr($srcset($media, 'webp')) ?>" sizes="<?= attr($sizes) ?>">
    <img
      src="<?= attr($url->media($media->derivativeFilename($media->defaultWidth(), 'jpg'))) ?>"
      srcset="<?= attr($srcset($media, 'jpg')) ?>"
      sizes="<?= attr($sizes) ?>"
      width="<?= attr($media->width) ?>"
      height="<?= attr($media->height) ?>"
      alt="<?= attr($media->alt($locale)) ?>"
      style="object-position: <?= attr($media->objectPosition()) ?>"
      decoding="async"
      <?php if ($prioritaire) : ?>fetchpriority="high"<?php else : ?>loading="lazy"<?php endif; ?>
    >
  </picture>
<?php else : ?>
  <span><?= e($etiquette) ?></span>
<?php endif; ?>
</div>
