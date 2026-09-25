<?php

/**
 * Barre fixe de la génération statique (retours du 2026-09-25, point 7).
 *
 * Nombre de pages statiques, numéro de génération, date de la dernière, état
 * (à jour ou périmé) et bouton de régénération. Le journal, de type terminal,
 * s'ouvre et se ferme (<details>) : une ligne horodatée et chronométrée par
 * page, puis le temps total. Fonctionne sans JavaScript ; admin-generation.js
 * poste en arrière-plan et régénère d'office un site périmé.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Service\StaticSite\GenerationState;

/** @var GenerationState $etat */
$etat = $data['generation'];
$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$paris = new DateTimeZone('Europe/Paris');
$heure = static fn (string $iso, string $format): string => (new DateTimeImmutable($iso))->setTimezone($paris)->format($format);
$secondes = static fn (int $ms): string => number_format($ms / 1000, 1, ',', ' ');
?>
<aside class="barre-generation" aria-label="Génération statique" data-generation<?php if ($etat->stale) : ?> data-stale<?php endif; ?>>
    <div class="barre-ligne">
        <?php if ($etat->number === 0) : ?>
        <span class="barre-etat barre-etat--perime">Aucune génération : le site est servi par PHP</span>
        <?php else : ?>
        <span class="barre-pages"><?= e($etat->stale ? 0 : $etat->count) ?> pages statiques</span>
        <span class="barre-numero">Génération n° <?= e($etat->number) ?></span>
        <?php if ($etat->at !== null) : ?>
        <span class="barre-date">le <?= e($heure($etat->at, 'd/m/Y à H:i')) ?></span>
        <?php endif; ?>
        <?php if ($etat->stale) : ?>
        <span class="barre-etat barre-etat--perime">Site statique périmé : servi par PHP jusqu’à la régénération</span>
        <?php else : ?>
        <span class="barre-etat">À jour</span>
        <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="<?= attr($base . '/admin/generation') ?>" class="barre-action" data-generation-form>
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <button type="submit" class="bouton">Régénérer</button>
        </form>
    </div>

    <?php if ($etat->log !== []) : ?>
    <details class="barre-journal-bloc">
        <summary>Journal</summary>
        <pre class="barre-journal" role="log"><?php foreach ($etat->log as $ligne) : ?>
<?= e(sprintf(
    '[%s] %-60s %5d ms%s',
    $heure($ligne['at'], 'H:i:s.v'),
    $ligne['path'],
    $ligne['ms'],
    $ligne['status'] === 200 ? '' : '  (' . $ligne['status'] . ', non écrite)',
)) ?>
<?php endforeach; ?>
<?= e('Total : ' . $etat->count . ' pages en ' . $secondes($etat->totalMs) . ' s') ?></pre>
    </details>
    <?php endif; ?>
</aside>
