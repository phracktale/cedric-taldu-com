<?php

/**
 * Paramètres › Impression (demande du 2026-09-30) : formats d'impression visés
 * et seuils de résolution. Trois lignes vides pour ajouter un format ; vider
 * une ligne la retire.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Catalog\PrintSettings;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var PrintSettings $reglage */
$reglage = $data['reglage'];
/** @var list<string> $erreurs */
$erreurs = is_array($data['erreurs'] ?? null) ? $data['erreurs'] : [];
/** @var array<string, string> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];
$v = static fn (string $nom, string $defaut): string => $saisie === [] ? $defaut : (string) ($saisie[$nom] ?? '');
$cm = static fn (int $mm): string => rtrim(rtrim(number_format($mm / 10, 1, ',', ''), '0'), ',');
$lignes = min(PrintSettings::MAX_FORMATS, count($reglage->formats) + 3);
?>
<div class="admin-page">
    <h1>Impression</h1>

    <p class="aide">
        Une seule image haute définition par œuvre sert aux vignettes, au zoom et à l’impression.
        Indiquez ici les formats d’impression visés : chaque image de la
        <a href="<?= attr($base . '/admin/medias') ?>">médiathèque</a> indique alors, format par format,
        si sa résolution suffit, et la taille minimale recommandée.
    </p>

    <?php if (($data['enregistre'] ?? false) === true) : ?>
    <p class="succes" role="status">Les réglages d’impression ont été enregistrés.</p>
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

    <form method="post" action="<?= attr($base . '/admin/impression') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <fieldset>
            <legend>Résolution</legend>
            <div class="grille-champs">
                <p class="champ">
                    <label for="dpi_cible">Résolution cible (dpi)</label>
                    <input type="number" id="dpi_cible" name="dpi_cible" value="<?= attr($v('dpi_cible', (string) $reglage->targetDpi)) ?>" min="72" max="1200" class="champ-court">
                    <span class="champ-aide">Au-delà : impression optimale. 300 dpi est la référence en tirage d’art.</span>
                </p>
                <p class="champ">
                    <label for="dpi_minimum">Résolution minimale acceptable (dpi)</label>
                    <input type="number" id="dpi_minimum" name="dpi_minimum" value="<?= attr($v('dpi_minimum', (string) $reglage->minimumDpi)) ?>" min="72" max="1200" class="champ-court">
                    <span class="champ-aide">En dessous, l’image est jugée trop petite pour le format.</span>
                </p>
            </div>
        </fieldset>

        <fieldset>
            <legend>Formats d’impression</legend>
            <div class="tableau-defilant">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th scope="col">Nom</th>
                            <th scope="col">Largeur (cm)</th>
                            <th scope="col">Hauteur (cm)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($n = 0; $n < $lignes; $n++) : ?>
                            <?php $format = $reglage->formats[$n] ?? null; ?>
                        <tr>
                            <td><input type="text" name="<?= attr('f' . $n . '_nom') ?>" value="<?= attr($v('f' . $n . '_nom', $format['name'] ?? '')) ?>" maxlength="60" aria-label="Nom du format"></td>
                            <td><input type="text" inputmode="decimal" name="<?= attr('f' . $n . '_largeur') ?>" value="<?= attr($v('f' . $n . '_largeur', $format === null ? '' : $cm($format['widthMm']))) ?>" class="champ-court" aria-label="Largeur en centimètres"></td>
                            <td><input type="text" inputmode="decimal" name="<?= attr('f' . $n . '_hauteur') ?>" value="<?= attr($v('f' . $n . '_hauteur', $format === null ? '' : $cm($format['heightMm']))) ?>" class="champ-court" aria-label="Hauteur en centimètres"></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <p class="champ-aide">Le sens n’importe pas : chaque format est comparé à l’image dans son propre sens.</p>
        </fieldset>

        <p class="actions"><button type="submit" class="bouton">Enregistrer</button></p>
    </form>
</div>
