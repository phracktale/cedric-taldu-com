<?php

/**
 * Bouton d'appel à l'action paramétrable (revue du 2026-09-24).
 *
 * Style et alignement viennent de listes fermées ({@see App\Domain\Editorial\Cta}) ;
 * l'URL est déjà résolue par CtaLinker, préfixe de chemin compris.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Editorial\Cta;

$cta = $data['cta'] ?? null;
$href = is_string($data['href'] ?? null) ? $data['href'] : '';
$classe = is_string($data['class'] ?? null) ? ' ' . $data['class'] : '';
?>
<?php if ($cta instanceof Cta && $href !== '') : ?>
<p class="cta-row cta-row--<?= attr($cta->align) ?><?= attr($classe) ?>">
  <a class="btn btn-<?= attr($cta->style) ?>" href="<?= attr($href) ?>">
    <?= e($cta->label) ?>
  </a>
</p>
<?php endif; ?>
