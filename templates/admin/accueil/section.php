<?php

/**
 * Contenu d'une section de l'accueil (revue du 2026-09-24).
 *
 * Textes par langue, CTA, et selon la section : fond du hero, portrait de
 * l'atelier, cellules du triptyque ou œuvres de la vitrine.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Editorial\HomeSectionForm;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$section = is_string($data['section'] ?? null) ? $data['section'] : '';
/** @var array<string, string> $valeurs */
$valeurs = is_array($data['valeurs'] ?? null) ? $data['valeurs'] : [];
/** @var array<int, string> $oeuvres */
$oeuvres = is_array($data['oeuvres'] ?? null) ? $data['oeuvres'] : [];
/** @var array<int, string> $rubriques */
$rubriques = is_array($data['rubriques'] ?? null) ? $data['rubriques'] : [];
$v = static fn (string $champ): string => $valeurs[$champ] ?? '';

$langues = ['fr' => 'Français', 'en' => 'English'];
?>
<div class="admin-page admin-page--etroite">
    <h1><?= e($data['titre'] ?? '') ?></h1>

    <p><a href="<?= attr($base . '/admin/accueil') ?>">← Ordre des sections</a></p>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/accueil/' . $section) ?>" class="formulaire"
          enctype="multipart/form-data" data-surveiller>
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <?php if (HomeSectionForm::TEXTS[$section] !== [] || $section === 'triptyque') : ?>
        <div data-onglets-langue>
        <?php foreach ($langues as $langue => $libelle) : ?>
            <section class="panneau-langue" data-langue="<?= attr($langue) ?>" data-libelle="<?= attr($libelle) ?>">
                <fieldset>
                    <legend><?= e($libelle) ?></legend>

                    <?php foreach (HomeSectionForm::TEXTS[$section] as $champ => [$etiquette, $type, $max]) : ?>
                    <?php $nom = $champ . '_' . $langue; ?>
                    <p class="champ">
                        <label for="<?= attr($nom) ?>"><?= e($etiquette) ?></label>
                        <?php if ($type === 'textarea') : ?>
                        <textarea id="<?= attr($nom) ?>" name="<?= attr($nom) ?>" rows="5"
                                  maxlength="<?= attr($max) ?>"><?= e($v($nom)) ?></textarea>
                        <?php else : ?>
                        <input type="text" id="<?= attr($nom) ?>" name="<?= attr($nom) ?>"
                               maxlength="<?= attr($max) ?>" value="<?= attr($v($nom)) ?>">
                        <?php endif; ?>
                    </p>
                    <?php endforeach; ?>

                    <?php if ($section === 'triptyque') : ?>
                        <?php for ($i = 1; $i <= HomeSectionForm::CELLS; $i++) : ?>
                        <?php $titre = 'cellule' . $i . '_titre_' . $langue; $texte = 'cellule' . $i . '_texte_' . $langue; ?>
                    <p class="champ">
                        <label for="<?= attr($titre) ?>">Volet <?= e((string) $i) ?> — titre</label>
                        <input type="text" id="<?= attr($titre) ?>" name="<?= attr($titre) ?>" maxlength="300"
                               value="<?= attr($v($titre)) ?>">
                    </p>
                    <p class="champ">
                        <label for="<?= attr($texte) ?>">Volet <?= e((string) $i) ?> — texte</label>
                        <textarea id="<?= attr($texte) ?>" name="<?= attr($texte) ?>" rows="3"
                                  maxlength="2000"><?= e($v($texte)) ?></textarea>
                    </p>
                        <?php endfor; ?>
                    <?php endif; ?>
                </fieldset>
            </section>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($section === 'vitrine') : ?>
        <fieldset>
            <legend>Œuvres présentées</legend>
            <p class="champ-aide">Trois œuvres, dans l’ordre d’affichage ; celle du milieu est présentée plus haute.</p>
            <?php for ($i = 1; $i <= HomeSectionForm::CELLS; $i++) : ?>
            <p class="champ">
                <label for="vitrine_<?= attr($i) ?>">Œuvre <?= e((string) $i) ?></label>
                <select id="vitrine_<?= attr($i) ?>" name="vitrine_<?= attr($i) ?>">
                    <option value="">—</option>
                    <?php foreach ($oeuvres as $id => $titre) : ?>
                    <option value="<?= attr($id) ?>" <?php if ($v('vitrine_' . $i) === (string) $id) : ?>selected<?php endif; ?>><?= e($titre) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <?php endfor; ?>
        </fieldset>
        <?php endif; ?>

        <?php if ($section === 'hero') : ?>
            <?= $partial('admin/partials/media-champ', [
                'nom' => 'fond', 'legende' => 'Image de fond', 'valeur' => $v('fond'), 'base' => $base,
            ]) ?>
        <fieldset>
            <legend>Couleurs</legend>
            <div class="grille-champs">
                <p class="champ">
                    <label for="fond_couleur">Couleur de fond</label>
                    <input type="text" id="fond_couleur" name="fond_couleur" maxlength="7"
                           pattern="#[0-9a-fA-F]{6}" placeholder="#1a1a1a" value="<?= attr($v('fond_couleur')) ?>">
                    <span class="champ-aide">Format #rrggbb. Vide : fond du site.</span>
                </p>
                <p class="champ">
                    <label for="fond_ton">Texte</label>
                    <select id="fond_ton" name="fond_ton">
                        <?php foreach (HomeSectionForm::TONES as $ton => $libelle) : ?>
                        <option value="<?= attr($ton) ?>" <?php if ($v('fond_ton') === $ton) : ?>selected<?php endif; ?>><?= e($libelle) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="champ-aide">Choisissez « Texte clair » sur une image ou une couleur sombre.</span>
                </p>
            </div>
        </fieldset>
        <?php endif; ?>

        <?php if ($section === 'atelier') : ?>
            <?= $partial('admin/partials/media-champ', [
                'nom' => 'portrait', 'legende' => 'Portrait', 'valeur' => $v('portrait'), 'base' => $base,
            ]) ?>
        <?php endif; ?>

        <?php if (HomeSectionForm::hasCta($section)) : ?>
            <?= $partial('admin/partials/cta-champs', ['valeurs' => $valeurs, 'rubriques' => $rubriques]) ?>
        <?php endif; ?>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
        </p>
    </form>
</div>
