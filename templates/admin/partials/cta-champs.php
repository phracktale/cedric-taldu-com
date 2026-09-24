<?php

/**
 * Champs d'un bouton d'appel à l'action (revue du 2026-09-24).
 *
 * Libellé par langue, cible (liste fermée, galerie précise ou adresse libre),
 * style et alignement. Un libellé vide reprend le libellé par défaut.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Editorial\Cta;

/** @var array<string, string> $valeurs */
$valeurs = is_array($data['valeurs'] ?? null) ? $data['valeurs'] : [];
/** @var array<int, string> $rubriques */
$rubriques = is_array($data['rubriques'] ?? null) ? $data['rubriques'] : [];
$legende = is_string($data['legende'] ?? null) ? $data['legende'] : 'Bouton';
$aide = is_string($data['aide'] ?? null) ? $data['aide'] : 'Laissez le libellé vide pour garder le libellé par défaut.';
$v = static fn (string $champ): string => $valeurs[$champ] ?? '';
?>
<fieldset>
    <legend><?= e($legende) ?></legend>

    <p class="champ champ-inline">
        <input type="checkbox" id="cta_affiche" name="cta_affiche" value="1"
            <?php if ($v('cta_affiche') !== '') : ?>checked<?php endif; ?>>
        <label for="cta_affiche">Afficher le bouton</label>
    </p>

    <div class="grille-champs">
        <p class="champ">
            <label for="cta_fr">Libellé (français)</label>
            <input type="text" id="cta_fr" name="cta_fr" maxlength="300" value="<?= attr($v('cta_fr')) ?>">
        </p>
        <p class="champ">
            <label for="cta_en">Libellé (anglais)</label>
            <input type="text" id="cta_en" name="cta_en" maxlength="300" value="<?= attr($v('cta_en')) ?>">
        </p>
    </div>
    <p class="champ-aide"><?= e($aide) ?></p>

    <div class="grille-champs">
        <p class="champ">
            <label for="cta_target">Mène vers</label>
            <select id="cta_target" name="cta_target">
                <?php foreach (Cta::TARGETS as $cible => $libelle) : ?>
                <option value="<?= attr($cible) ?>" <?php if ($v('cta_target') === $cible) : ?>selected<?php endif; ?>><?= e($libelle) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="champ">
            <label for="cta_category">Galerie (si « Une galerie précise »)</label>
            <select id="cta_category" name="cta_category">
                <option value="">—</option>
                <?php foreach ($rubriques as $id => $titre) : ?>
                <option value="<?= attr($id) ?>" <?php if ($v('cta_category') === (string) $id) : ?>selected<?php endif; ?>><?= e($titre) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
    </div>

    <p class="champ">
        <label for="cta_url">Adresse (si « Adresse libre »)</label>
        <input type="text" id="cta_url" name="cta_url" maxlength="500" value="<?= attr($v('cta_url')) ?>"
               placeholder="/fr/livret ou https://…">
        <span class="champ-aide">
            Un chemin du site commençant par « / » (sans le préfixe), ou une adresse https.
            Toute autre forme est refusée et le bouton mène aux galeries.
        </span>
    </p>

    <div class="grille-champs">
        <p class="champ">
            <label for="cta_style">Style</label>
            <select id="cta_style" name="cta_style">
                <?php foreach (Cta::STYLES as $style => $libelle) : ?>
                <option value="<?= attr($style) ?>" <?php if ($v('cta_style') === $style) : ?>selected<?php endif; ?>><?= e($libelle) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="champ">
            <label for="cta_align">Alignement</label>
            <select id="cta_align" name="cta_align">
                <?php foreach (Cta::ALIGNS as $align => $libelle) : ?>
                <option value="<?= attr($align) ?>" <?php if ($v('cta_align') === $align) : ?>selected<?php endif; ?>><?= e($libelle) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
    </div>
</fieldset>
