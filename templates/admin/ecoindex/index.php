<?php

/**
 * Évaluation EcoIndex du site (retours du 2026-09-25, point 8).
 *
 * Mesures prises à la dernière génération statique, sans navigateur : DOM
 * compté sur le HTML, requêtes et poids retrouvés à partir des fichiers
 * servis. Formule et seuils de GreenIT (ecoindex.fr).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

/** @var list<array{path: string, score: int, grade: string, dom: int, requests: int, kb: float, ges: float, water: float}> $pages */
$pages = is_array($data['pages'] ?? null) ? $data['pages'] : [];
/** @var array{score: int, grade: string}|null $moyenne */
$moyenne = is_array($data['moyenne'] ?? null) ? $data['moyenne'] : null;
$date = is_string($data['date'] ?? null) ? $data['date'] : null;
$nombre = static fn (float $n, int $decimales = 1): string => number_format($n, $decimales, ',', ' ');
?>
<div class="admin-page">
    <h1>EcoIndex</h1>

    <p class="aide">
        L’EcoIndex note l’empreinte d’une page de A à G à partir de trois mesures : le nombre
        d’éléments du DOM, le nombre de requêtes et le poids transféré (formule GreenIT,
        ecoindex.fr). Il est calculé à chaque génération statique, pour chaque page, sans
        navigateur : c’est une estimation proche de la mesure en ligne.
    </p>

    <?php if ($pages === [] || $moyenne === null) : ?>
    <p>Aucune mesure : lancez une génération depuis la barre en bas de l’écran.</p>
    <?php else : ?>
    <p class="eco-resume">
        Moyenne du site :
        <span class="eco-note eco-note--<?= attr(strtolower($moyenne['grade'])) ?>"><?= e($moyenne['grade']) ?></span>
        <?= e($moyenne['score']) ?> / 100 sur <?= e(count($pages)) ?> pages
        <?php if ($date !== null) : ?>
        — génération n° <?= e($data['numero'] ?? '') ?> du
        <?= e((new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i')) ?>
        <?php endif; ?>
    </p>

    <div class="tableau-defilant">
        <table class="tableau">
            <caption>Pages, de la moins bonne note à la meilleure</caption>
            <thead>
                <tr>
                    <th scope="col">Page</th>
                    <th scope="col">Note</th>
                    <th scope="col">Score</th>
                    <th scope="col">Éléments du DOM</th>
                    <th scope="col">Requêtes</th>
                    <th scope="col">Poids (Ko)</th>
                    <th scope="col">GES (gCO2e)</th>
                    <th scope="col">Eau (cl)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pages as $page) : ?>
                <tr>
                    <td><?= e($page['path']) ?></td>
                    <td><span class="eco-note eco-note--<?= attr(strtolower($page['grade'])) ?>"><?= e($page['grade']) ?></span></td>
                    <td><?= e($page['score']) ?></td>
                    <td><?= e($page['dom']) ?></td>
                    <td><?= e($page['requests']) ?></td>
                    <td><?= e($nombre($page['kb'])) ?></td>
                    <td><?= e($nombre($page['ges'], 2)) ?></td>
                    <td><?= e($nombre($page['water'], 2)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
