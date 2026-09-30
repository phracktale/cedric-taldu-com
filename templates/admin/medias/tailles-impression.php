<?php

/**
 * Fiche média (demande du 2026-09-30) : une seule image haute définition sert
 * à l'affichage, au zoom et à l'impression. Deux tableaux, pour information :
 *
 *  - les tailles produites pour la fiche œuvre, point de rupture par point de
 *    rupture (formats automatiques) ;
 *  - la qualité d'impression pour chaque format de Paramètres › Impression.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Catalog\ImageBreakpoints;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
/** @var list<array{media: string|null, candidates: list<array{0: int, 1: string}>}> $tailles */
$tailles = is_array($data['tailles'] ?? null) ? $data['tailles'] : [];
/** @var list<array{name: string, widthMm: int, heightMm: int, dpi: int, verdict: string, requiredPx: array{0: int, 1: int}}> $impression */
$impression = is_array($data['impression'] ?? null) ? $data['impression'] : [];
$cm = static fn (int $mm): string => rtrim(rtrim(number_format($mm / 10, 1, ',', ''), '0'), ',');
$verdicts = ['optimal' => 'Optimal', 'acceptable' => 'Acceptable', 'insuffisant' => 'Insuffisant'];

// Libellés des points de rupture, du plus large au plus étroit (ordre de sources()).
$points = array_reverse(ImageBreakpoints::FICHE);
$insuffisants = array_values(array_filter($impression, static fn (array $q): bool => $q['verdict'] === 'insuffisant'));
?>
<section>
    <h2>Tailles produites</h2>
    <p class="aide">Formats choisis automatiquement (WebP, JPEG de repli) ; chaque taille est affichée sans être redimensionnée.</p>
    <div class="tableau-defilant">
        <table class="tableau">
            <thead>
                <tr>
                    <th scope="col">Fiche œuvre</th>
                    <th scope="col">Largeur affichée</th>
                    <th scope="col">Fichiers</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tailles as $i => $source) : ?>
                    <?php [$min, $rem] = $points[$i] ?? [0, 0]; ?>
                <tr>
                    <td><?php if ($min > 0) : ?><?= e('écran ≥ ' . $min . 'rem') ?><?php else : ?><?= e('écran < ' . ($points[$i - 1][0] ?? 0) . 'rem') ?><?php endif; ?></td>
                    <td><?= e($rem . 'rem') ?></td>
                    <td><?= e(implode(', ', array_map(static fn (array $c): string => $c[0] . ' px (' . $c[1] . ')', $source['candidates']))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section>
    <h2>Impression</h2>
    <?php if ($impression === []) : ?>
    <p class="aide">
        Aucun format d’impression n’est défini : indiquez les formats visés dans
        <a href="<?= attr($base . '/admin/impression') ?>">Paramètres › Impression</a>.
    </p>
    <?php else : ?>
        <?php foreach ($insuffisants as $format) : ?>
    <p class="erreur" role="alert">Trop petite pour imprimer en <?= e($format['name']) ?> cm : <?= e($format['requiredPx'][0] . ' × ' . $format['requiredPx'][1]) ?> px recommandés.</p>
        <?php endforeach; ?>
    <div class="tableau-defilant">
        <table class="tableau">
            <thead>
                <tr>
                    <th scope="col">Format</th>
                    <th scope="col">Résolution obtenue</th>
                    <th scope="col">Verdict</th>
                    <th scope="col">Dimension minimale recommandée</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($impression as $q) : ?>
                <tr>
                    <td><?= e($q['name'] . ' cm') ?></td>
                    <td><?= e($q['dpi'] . ' dpi') ?></td>
                    <td><span class="impression impression--<?= attr($q['verdict']) ?>"><?= e($verdicts[$q['verdict']] ?? $q['verdict']) ?></span></td>
                    <td><?= e($q['requiredPx'][0] . ' × ' . $q['requiredPx'][1] . ' px') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="champ-aide"><a href="<?= attr($base . '/admin/impression') ?>">Formats et seuils d’impression</a></p>
    <?php endif; ?>
</section>
