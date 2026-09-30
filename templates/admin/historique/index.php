<?php

/**
 * Paramètres › Historique (demande du 2026-09-30) : éléments modifiés, et
 * purge irréversible derrière une alerte où il faut taper PURGER.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<array{type: string, key: string, titre: string, count: int, last: string}> $sujets */
$sujets = is_array($data['sujets'] ?? null) ? $data['sujets'] : [];
/** @var array{count: int, bytes: int, oldest: string|null} $stats */
$stats = $data['stats'];
$mot = is_string($data['confirmation'] ?? null) ? $data['confirmation'] : 'PURGER';
$taille = number_format($stats['bytes'] / 1024, 0, ',', ' ') . ' Ko';
$date = static fn (string $sql): string => (new DateTimeImmutable($sql))->format('d/m/Y à H:i');
?>
<div class="admin-page">
    <h1>Historique</h1>

    <p class="aide">
        Chaque modification de la structure (templates, accueil, menus…) et des blocs réutilisables garde la
        version précédente. En cas de fausse manipulation, ouvrez l’élément et restaurez une version :
        la restauration se défait elle aussi, elle garde l’état qu’elle remplace.
    </p>

    <?php if (is_string($data['purge'] ?? null)) : ?>
    <p class="succes" role="status">Historique purgé : <?= e($data['purge']) ?> version(s) effacée(s).</p>
    <?php endif; ?>
    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <?php if ($sujets === []) : ?>
    <p class="aide">Aucune version gardée pour l’instant.</p>
    <?php else : ?>
    <div class="tableau-defilant">
        <table class="tableau">
            <thead>
                <tr>
                    <th scope="col">Élément</th>
                    <th scope="col">Versions</th>
                    <th scope="col">Dernière modification</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sujets as $sujet) : ?>
                <tr>
                    <td><a href="<?= attr($base . '/admin/historique/' . $sujet['type'] . '/' . rawurlencode($sujet['key'])) ?>"><?= e($sujet['titre']) ?></a></td>
                    <td><?= e((string) $sujet['count']) ?></td>
                    <td><?= e($date($sujet['last'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <section class="zone-danger" aria-labelledby="purge-titre">
        <h2 id="purge-titre">Purger l’historique</h2>
        <p>
            <?= e($stats['count'] . ' version(s) gardée(s), ' . $taille) ?><?php if ($stats['oldest'] !== null) : ?>,
            la plus ancienne du <?= e($date($stats['oldest'])) ?><?php endif; ?>.
        </p>
        <p class="zone-danger-alerte" role="note">
            <strong>Attention : la purge est irréversible.</strong> Les versions effacées ne pourront plus
            jamais être restaurées : une fausse manipulation antérieure deviendrait définitive.
        </p>
        <form method="post" action="<?= attr($base . '/admin/historique/purge') ?>" class="formulaire">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <fieldset>
                <legend>Que purger ?</legend>
                <p class="champ-choix">
                    <input type="radio" id="portee_anciennes" name="portee" value="anciennes" checked>
                    <label for="portee_anciennes">Les versions de plus de</label>
                    <input type="number" id="jours" name="jours" min="1" max="3650" value="90" aria-label="Nombre de jours">
                    jours
                </p>
                <p class="champ-choix">
                    <input type="radio" id="portee_tout" name="portee" value="tout">
                    <label for="portee_tout">Tout l’historique</label>
                </p>
            </fieldset>
            <p class="champ">
                <label for="confirmation">Pour confirmer, tapez <strong><?= e($mot) ?></strong> en majuscules</label>
                <input type="text" id="confirmation" name="confirmation" autocomplete="off" spellcheck="false" required>
            </p>
            <p class="actions">
                <button type="submit" class="bouton bouton--danger">Purger définitivement</button>
            </p>
        </form>
    </section>
</div>
