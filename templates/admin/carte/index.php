<?php

/**
 * Modules › Carte interactive (retours du 2026-09-25) : point central, zoom et
 * marqueurs. Trois lignes vides pour ajouter des lieux ; vider une ligne la
 * retire. La carte s'affiche là où un bloc « Carte interactive » est placé
 * (pages, actus, bibliothèque de blocs — donc aussi l'accueil).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Editorial\MapSettings;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var MapSettings $carte */
$carte = $data['carte'];
/** @var list<string> $erreurs */
$erreurs = is_array($data['erreurs'] ?? null) ? $data['erreurs'] : [];
/** @var array<string, string> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];
$v = static fn (string $nom, string $defaut): string => $saisie === [] ? $defaut : (string) ($saisie[$nom] ?? '');
$lignes = min(MapSettings::MAX_MARKERS, count($carte->markers) + 3);
?>
<div class="admin-page">
    <h1>Carte interactive</h1>

    <p class="aide">
        Réglez le point central, le zoom (1 : monde entier, 18 : rue) et les lieux à signaler.
        Pour trouver des coordonnées : sur openstreetmap.org, clic droit sur le lieu puis
        « Afficher l’adresse ». La carte apparaît là où vous placez un bloc « Carte interactive »
        (éditeur de blocs d’une page, d’une actu ou de la <a href="<?= attr($base . '/admin/blocs') ?>">bibliothèque</a>).
        Le fond de carte OpenStreetMap n’est chargé qu’au clic du visiteur.
    </p>

    <?php if (($data['enregistre'] ?? false) === true) : ?>
    <p class="succes" role="status">La carte a été enregistrée.</p>
    <?php endif; ?>
    <?php if ($erreurs !== []) : ?>
    <div class="erreur" role="alert">
        <p>Rien n’a été enregistré :</p>
        <ul>
            <?php foreach ($erreurs as $erreur) : ?>
            <li><?= e($erreur) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/carte') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <fieldset>
            <legend>Point central</legend>
            <div class="grille-champs">
                <p class="champ">
                    <label for="lat">Latitude</label>
                    <input type="text" inputmode="decimal" id="lat" name="lat" value="<?= attr($v('lat', (string) $carte->latitude)) ?>" required>
                </p>
                <p class="champ">
                    <label for="lng">Longitude</label>
                    <input type="text" inputmode="decimal" id="lng" name="lng" value="<?= attr($v('lng', (string) $carte->longitude)) ?>" required>
                </p>
                <p class="champ">
                    <label for="zoom">Zoom</label>
                    <input type="number" id="zoom" name="zoom" value="<?= attr($v('zoom', (string) $carte->zoom)) ?>" min="1" max="18" class="champ-court" required>
                </p>
            </div>
        </fieldset>

        <fieldset>
            <legend>Lieux</legend>
            <div class="tableau-defilant">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th scope="col">Titre</th>
                            <th scope="col">Description</th>
                            <th scope="col">Latitude</th>
                            <th scope="col">Longitude</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($n = 0; $n < $lignes; $n++) : ?>
                            <?php $lieu = $carte->markers[$n] ?? null; ?>
                        <tr>
                            <td><input type="text" name="<?= attr('m' . $n . '_titre') ?>" value="<?= attr($v('m' . $n . '_titre', $lieu['title'] ?? '')) ?>" maxlength="120" aria-label="Titre du lieu"></td>
                            <td><input type="text" name="<?= attr('m' . $n . '_description') ?>" value="<?= attr($v('m' . $n . '_description', $lieu['description'] ?? '')) ?>" maxlength="500" aria-label="Description du lieu"></td>
                            <td><input type="text" inputmode="decimal" name="<?= attr('m' . $n . '_lat') ?>" value="<?= attr($v('m' . $n . '_lat', $lieu === null ? '' : (string) $lieu['lat'])) ?>" class="champ-court" aria-label="Latitude"></td>
                            <td><input type="text" inputmode="decimal" name="<?= attr('m' . $n . '_lng') ?>" value="<?= attr($v('m' . $n . '_lng', $lieu === null ? '' : (string) $lieu['lng'])) ?>" class="champ-court" aria-label="Longitude"></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </fieldset>

        <p class="actions"><button type="submit" class="bouton">Enregistrer la carte</button></p>
    </form>
</div>
