<?php

/**
 * Fiche d'edition d'une image.
 *
 * Tout ce qui compte se fait SANS JavaScript (04-back-office §12) : texte
 * alternatif et legende par langue, copyright, point focal, remplacement du
 * fichier. Le recadrage, lui, est une amelioration — il exige de tracer une
 * zone a la souris — et son panneau reste masque tant que le module admin.js ne
 * l'a pas active. Le point focal joue le role de cadrage pour qui n'a pas JS.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var array<string, mixed> $media */
$media = is_array($data['media'] ?? null) ? $data['media'] : [];

/** @var array<string, array{alt: string, caption: string|null}> $traductions */
$traductions = is_array($data['traductions'] ?? null) ? $data['traductions'] : [];

/** @var array{categories: int, artworks: int, galleries: int} $usages */
$usages = is_array($data['usages'] ?? null)
    ? $data['usages']
    : ['categories' => 0, 'artworks' => 0, 'galleries' => 0];

$id = (int) ($media['id'] ?? 0);
$basename = is_string($media['public_basename'] ?? null) ? $media['public_basename'] : '';
$largeur = (int) ($media['width'] ?? 0);
$hauteur = (int) ($media['height'] ?? 0);
$totalUsages = array_sum($usages);

// Plus grand derive disponible sous 1600 px, pour un apercu net sans charger
// l'original. Media::availableWidths() garantit au moins le derive de 320 px.
$apercu = 320;
foreach (\App\Domain\Catalog\Media::WIDTHS as $candidat) {
    if ($candidat <= min($largeur, 1600)) {
        $apercu = $candidat;
    }
}

$langues = ['fr' => 'Français', 'en' => 'English'];

$valeur = static function (string $langue, string $colonne) use ($traductions): string {
    $traduction = $traductions[$langue] ?? [];

    return is_array($traduction) && is_string($traduction[$colonne] ?? null) ? $traduction[$colonne] : '';
};

?>
<div class="admin-page admin-page--etroite">
    <p><a href="<?= attr($base . '/admin/medias') ?>">&larr; Médiathèque</a></p>

    <h1>Modifier une image</h1>

    <?php if (is_string($data['succes'] ?? null)) : ?>
    <p class="succes" role="status"><?= e($data['succes']) ?></p>
    <?php endif; ?>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <figure class="media-apercu">
        <img src="<?= attr($url->media($basename . '-' . $apercu . '.jpg')) ?>"
             alt="<?= attr($valeur('fr', 'alt')) ?>"
             width="<?= attr($largeur) ?>" height="<?= attr($hauteur) ?>"
             data-cropper-image>
        <figcaption>
            <?= e($media['original_name'] ?? 'Sans nom') ?> — <?= e($largeur) ?> × <?= e($hauteur) ?> px
        </figcaption>
    </figure>

    <?php
    // Panneau de recadrage : masque par defaut, revele par admin.js. La scene et
    // ses reperes sont pilotes en JS (positions posees via element.style, jamais
    // en attribut inline — CSP stricte, 06-securite §8).
    ?>
    <section class="media-recadrage" data-cropper hidden aria-label="Recadrer l’image">
        <h2>Recadrer</h2>
        <p class="champ-aide">
            Tracez la zone à conserver sur l’image ci-dessus, puis recadrez. Les
            déclinaisons sont régénérées et le point focal est réinitialisé.
        </p>
        <form method="post" action="<?= attr($base . '/admin/medias/' . $id . '/recadrage') ?>"
              data-cropper-form>
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <input type="hidden" name="crop_x" value="" data-cropper-input="x">
            <input type="hidden" name="crop_y" value="" data-cropper-input="y">
            <input type="hidden" name="crop_w" value="" data-cropper-input="w">
            <input type="hidden" name="crop_h" value="" data-cropper-input="h">
            <p class="actions">
                <button type="submit" class="bouton" data-cropper-submit disabled>Recadrer</button>
                <button type="button" class="bouton bouton--secondaire" data-cropper-reset>Annuler la sélection</button>
            </p>
        </form>
    </section>

    <form method="post" action="<?= attr($base . '/admin/medias/' . $id) ?>" class="formulaire" data-surveiller>
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <div data-onglets-langue>
        <?php foreach ($langues as $langue => $libelle) : ?>
            <section class="panneau-langue" data-langue="<?= attr($langue) ?>" data-libelle="<?= attr($libelle) ?>">
                <fieldset>
                    <legend><?= e($libelle) ?></legend>

                    <p class="champ">
                        <label for="alt_<?= attr($langue) ?>">Texte alternatif</label>
                        <input type="text" id="alt_<?= attr($langue) ?>" name="alt_<?= attr($langue) ?>"
                               value="<?= attr($valeur($langue, 'alt')) ?>" maxlength="255">
                        <span class="champ-aide">Décrit l’image pour les lecteurs d’écran et les moteurs.</span>
                    </p>

                    <p class="champ">
                        <label for="caption_<?= attr($langue) ?>">Légende</label>
                        <input type="text" id="caption_<?= attr($langue) ?>" name="caption_<?= attr($langue) ?>"
                               value="<?= attr($valeur($langue, 'caption')) ?>" maxlength="255">
                    </p>
                </fieldset>
            </section>
        <?php endforeach; ?>
        </div>

        <fieldset>
            <legend>Crédit et cadrage</legend>

            <p class="champ">
                <label for="copyright">Copyright</label>
                <input type="text" id="copyright" name="copyright"
                       value="<?= attr($media['copyright'] ?? '') ?>" maxlength="190">
                <span class="champ-aide">Mention de crédit, la même dans toutes les langues. Exemple : © Cédric Taldu.</span>
            </p>

            <div class="grille-champs">
                <p class="champ">
                    <label for="focal_x">Point focal — horizontal (%)</label>
                    <input type="number" id="focal_x" name="focal_x" min="0" max="100"
                           value="<?= attr($media['focal_x'] ?? '') ?>">
                </p>
                <p class="champ">
                    <label for="focal_y">Point focal — vertical (%)</label>
                    <input type="number" id="focal_y" name="focal_y" min="0" max="100"
                           value="<?= attr($media['focal_y'] ?? '') ?>">
                </p>
            </div>
            <span class="champ-aide">
                Le centre d’intérêt de l’image, pour que les vignettes recadrées ne coupent pas le sujet.
                Laissé vide, le centre est utilisé.
            </span>
        </fieldset>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
            <a class="bouton bouton--secondaire" href="<?= attr($base . '/admin/medias') ?>">Retour à la liste</a>
        </p>
    </form>

    <section class="formulaire">
        <h2>Remplacer l’image</h2>
        <p class="champ-aide">
            Le nouveau fichier prend la place de celui-ci : les œuvres et rubriques qui l’emploient
            le suivent. Les déclinaisons sont régénérées et le point focal réinitialisé.
        </p>
        <form method="post" action="<?= attr($base . '/admin/medias/' . $id . '/remplacement') ?>"
              enctype="multipart/form-data">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <p class="champ">
                <label for="image">Nouveau fichier</label>
                <input type="file" id="image" name="image"
                       accept="image/jpeg,image/png,image/webp" required>
            </p>
            <p class="actions">
                <button type="submit" class="bouton bouton--secondaire">Remplacer</button>
            </p>
        </form>
    </section>

    <section>
        <h2>Utilisation</h2>
        <?php if ($totalUsages === 0) : ?>
        <p class="aide">Cette image n’est employée nulle part.</p>

        <form method="post" action="<?= attr($base . '/admin/medias/' . $id . '/suppression') ?>"
              data-confirmation="Supprimer définitivement cette image ?">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <button type="submit" class="lien-bouton">Supprimer cette image</button>
        </form>
        <?php else : ?>
        <ul>
            <?php if ($usages['artworks'] > 0) : ?>
            <li>Image principale de <?= e($usages['artworks']) ?> œuvre(s).</li>
            <?php endif; ?>
            <?php if ($usages['galleries'] > 0) : ?>
            <li>Présente dans <?= e($usages['galleries']) ?> galerie(s) d’œuvre.</li>
            <?php endif; ?>
            <?php if ($usages['categories'] > 0) : ?>
            <li>Couverture de <?= e($usages['categories']) ?> rubrique(s).</li>
            <?php endif; ?>
        </ul>
        <p class="aide">Retirez l’image de ces emplacements avant de pouvoir la supprimer.</p>
        <?php endif; ?>
    </section>
</div>
