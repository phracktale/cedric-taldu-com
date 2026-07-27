<?php

/**
 * Création et modification d'un article (04-back-office §9).
 *
 * Les deux langues sont dans le MÊME formulaire, repliées en onglets par
 * admin.js une fois chargé ; sans lui, on fait défiler et on saisit tout de
 * même (règle du §12). Le corps est assaini à l'enregistrement.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var array<string, mixed>|null $article */
$article = is_array($data['article'] ?? null) ? $data['article'] : null;

/** @var array<string, string> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];

$action = $article === null
    ? $base . '/admin/actus'
    : $base . '/admin/actus/' . $article['id'];

// La saisie refusée prime sur la valeur en base : un formulaire rejeté ne doit
// pas faire retaper ce qui venait d'être écrit.
$valeur = static function (string $champ, string $langue, string $colonne) use ($saisie, $article): string {
    $poste = $saisie[$champ . '_' . $langue] ?? null;

    if (is_string($poste)) {
        return $poste;
    }

    $traduction = $article['translations'][$langue] ?? [];

    return is_array($traduction) && is_string($traduction[$colonne] ?? null) ? $traduction[$colonne] : '';
};

$champNeutre = static function (string $champ, string $colonne) use ($saisie, $article): string {
    $poste = $saisie[$champ] ?? null;

    if (is_string($poste)) {
        return $poste;
    }

    return is_string($article[$colonne] ?? null) ? $article[$colonne] : '';
};

$langues = ['fr' => 'Français', 'en' => 'English'];

?>
<div class="admin-page admin-page--etroite">
    <h1><?= e($data['titre'] ?? '') ?></h1>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= attr($action) ?>" class="formulaire" data-surveiller
          enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <div data-onglets-langue>
        <?php foreach ($langues as $langue => $libelle) : ?>
            <section class="panneau-langue" data-langue="<?= attr($langue) ?>" data-libelle="<?= attr($libelle) ?>">
                <fieldset>
                    <legend><?= e($libelle) ?></legend>

                    <p class="champ">
                        <label for="titre_<?= attr($langue) ?>">Titre<?php if ($langue === 'fr') : ?> (obligatoire)<?php endif; ?></label>
                        <input type="text" id="titre_<?= attr($langue) ?>" name="titre_<?= attr($langue) ?>"
                               value="<?= attr($valeur('titre', $langue, 'title')) ?>" maxlength="220">
                    </p>

                    <p class="champ">
                        <label for="slug_<?= attr($langue) ?>">Identifiant d’URL</label>
                        <input type="text" id="slug_<?= attr($langue) ?>" name="slug_<?= attr($langue) ?>"
                               value="<?= attr($valeur('slug', $langue, 'slug')) ?>" maxlength="190"
                               data-slug-depuis="titre_<?= attr($langue) ?>">
                        <span class="champ-aide">Laissé vide, il est déduit du titre.</span>
                    </p>

                    <p class="champ">
                        <label for="extrait_<?= attr($langue) ?>">Extrait</label>
                        <textarea id="extrait_<?= attr($langue) ?>" name="extrait_<?= attr($langue) ?>"
                                  maxlength="400" rows="3"><?= e($valeur('extrait', $langue, 'excerpt')) ?></textarea>
                        <span class="champ-aide">Résumé affiché dans la liste des actus. Texte simple.</span>
                    </p>

                    <p class="champ">
                        <label for="corps_<?= attr($langue) ?>">Corps de l’article</label>
                        <textarea id="corps_<?= attr($langue) ?>" name="corps_<?= attr($langue) ?>"
                                  rows="12"><?= e($valeur('corps', $langue, 'body')) ?></textarea>
                        <span class="champ-aide">
                            Mise en forme : titres &lt;h2&gt;/&lt;h3&gt;, &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, listes,
                            citations, liens, images. Tout le reste est retiré à l’enregistrement.
                        </span>
                    </p>

                    <div class="grille-champs">
                        <p class="champ">
                            <label for="meta_titre_<?= attr($langue) ?>">Titre pour les moteurs</label>
                            <input type="text" id="meta_titre_<?= attr($langue) ?>" name="meta_titre_<?= attr($langue) ?>"
                                   value="<?= attr($valeur('meta_titre', $langue, 'meta_title')) ?>" maxlength="180">
                        </p>

                        <p class="champ">
                            <label for="meta_description_<?= attr($langue) ?>">Description pour les moteurs</label>
                            <input type="text" id="meta_description_<?= attr($langue) ?>"
                                   name="meta_description_<?= attr($langue) ?>"
                                   value="<?= attr($valeur('meta_description', $langue, 'meta_description')) ?>"
                                   maxlength="300">
                        </p>
                    </div>
                </fieldset>
            </section>
        <?php endforeach; ?>
        </div>

        <fieldset>
            <legend>Exposition (facultatif)</legend>
            <div class="grille-champs">
                <p class="champ">
                    <label for="date_evenement">Date de l’événement</label>
                    <input type="date" id="date_evenement" name="date_evenement"
                           value="<?= attr($champNeutre('date_evenement', 'event_date')) ?>">
                    <span class="champ-aide">Renseignée, l’article est présenté comme une exposition.</span>
                </p>
                <p class="champ">
                    <label for="lieu_evenement">Lieu</label>
                    <input type="text" id="lieu_evenement" name="lieu_evenement" maxlength="200"
                           value="<?= attr($champNeutre('lieu_evenement', 'event_place')) ?>">
                </p>
            </div>
        </fieldset>

        <fieldset>
            <legend>Image à la une</legend>
            <p class="champ">
                <label for="couverture_fichier">Téléverser une image</label>
                <input type="file" id="couverture_fichier" name="couverture_fichier"
                       accept="image/jpeg,image/png,image/webp">
                <span class="champ-aide">
                    JPEG, PNG ou WebP. L’image rejoint la médiathèque et devient l’image à la une.
                </span>
            </p>
            <p class="champ">
                <label for="couverture">ou identifiant d’une image existante</label>
                <input type="number" id="couverture" name="couverture" min="1"
                       value="<?= attr($article['cover_media_id'] ?? '') ?>">
                <span class="champ-aide">
                    Le numéro affiché dans la <a href="<?= attr($base . '/admin/medias') ?>">médiathèque</a>.
                    Un fichier téléversé a la priorité.
                </span>
            </p>
        </fieldset>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
            <a class="bouton bouton--secondaire" href="<?= attr($base . '/admin/actus') ?>">Retour à la liste</a>
        </p>
    </form>
</div>
