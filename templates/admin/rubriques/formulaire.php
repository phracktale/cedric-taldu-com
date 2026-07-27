<?php

/**
 * Creation et modification d'une rubrique.
 *
 * Les deux langues sont dans le MEME formulaire, cote a cote. Le module
 * admin.js les replie en onglets une fois charge (04-back-office §5) ; sans lui,
 * l'artiste fait defiler et saisit tout de meme les deux — c'est la regle du §12.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var array<string, mixed>|null $rubrique */
$rubrique = is_array($data['rubrique'] ?? null) ? $data['rubrique'] : null;

/** @var array<string, string> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];

/** @var list<array<string, mixed>> $series */
$series = is_array($data['series'] ?? null) ? $data['series'] : [];

$action = $rubrique === null
    ? $base . '/admin/rubriques'
    : $base . '/admin/rubriques/' . $rubrique['id'];

/**
 * Valeur a afficher : la saisie refusee prime sur la valeur en base, sinon un
 * formulaire rejete ferait retaper ce qui venait d'etre ecrit.
 */
$valeur = static function (string $champ, string $langue, string $colonne) use ($saisie, $rubrique): string {
    $poste = $saisie[$champ . '_' . $langue] ?? null;

    if (is_string($poste)) {
        return $poste;
    }

    $traduction = $rubrique['translations'][$langue] ?? [];

    return is_array($traduction) && is_string($traduction[$colonne] ?? null) ? $traduction[$colonne] : '';
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
                               value="<?= attr($valeur('titre', $langue, 'title')) ?>" maxlength="200">
                    </p>

                    <p class="champ">
                        <label for="slug_<?= attr($langue) ?>">Identifiant d’URL</label>
                        <input type="text" id="slug_<?= attr($langue) ?>" name="slug_<?= attr($langue) ?>"
                               value="<?= attr($valeur('slug', $langue, 'slug')) ?>" maxlength="160"
                               data-slug-depuis="titre_<?= attr($langue) ?>">
                        <span class="champ-aide">Laissé vide, il est déduit du titre.</span>
                    </p>

                    <p class="champ">
                        <label for="surtitre_<?= attr($langue) ?>">Surtitre</label>
                        <input type="text" id="surtitre_<?= attr($langue) ?>" name="surtitre_<?= attr($langue) ?>"
                               value="<?= attr($valeur('surtitre', $langue, 'eyebrow')) ?>" maxlength="160">
                    </p>

                    <p class="champ">
                        <label for="description_<?= attr($langue) ?>">Description</label>
                        <textarea id="description_<?= attr($langue) ?>"
                                  name="description_<?= attr($langue) ?>"><?= e($valeur('description', $langue, 'description')) ?></textarea>
                        <span class="champ-aide">
                            Mise en forme simple : &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, listes, liens.
                            Tout le reste est retiré à l’enregistrement.
                        </span>
                    </p>

                    <p class="champ">
                        <label for="methode_<?= attr($langue) ?>">Texte « méthode »</label>
                        <textarea id="methode_<?= attr($langue) ?>"
                                  name="methode_<?= attr($langue) ?>"><?= e($valeur('methode', $langue, 'method_text')) ?></textarea>
                        <span class="champ-aide">Bande de bas de page de la rubrique. Facultatif.</span>
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
            <legend>Couverture</legend>
            <p class="champ">
                <label for="couverture_fichier">Téléverser une image</label>
                <input type="file" id="couverture_fichier" name="couverture_fichier"
                       accept="image/jpeg,image/png,image/webp">
                <span class="champ-aide">
                    JPEG, PNG ou WebP. L’image rejoint la médiathèque et devient la couverture.
                </span>
            </p>
            <p class="champ">
                <label for="couverture">ou identifiant d’une image existante</label>
                <input type="number" id="couverture" name="couverture" min="1"
                       value="<?= attr($rubrique['cover_media_id'] ?? '') ?>">
                <span class="champ-aide">
                    Le numéro affiché dans la <a href="<?= attr($base . '/admin/medias') ?>">médiathèque</a>.
                    Un fichier téléversé a la priorité.
                </span>
            </p>
        </fieldset>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
            <a class="bouton bouton--secondaire" href="<?= attr($base . '/admin/rubriques') ?>">Retour à la liste</a>
        </p>
    </form>

    <?php if ($rubrique !== null) : ?>
    <section>
        <h2>Séries</h2>

        <?php if ($series === []) : ?>
        <p class="aide">Aucune série. Les séries regroupent les œuvres d’une rubrique en sous-ensembles filtrables.</p>
        <?php else : ?>
        <table class="tableau">
            <thead><tr><th scope="col">Titre</th><th scope="col" class="colonne-actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($series as $serie) : ?>
                <tr>
                    <td><?= e($serie['translations']['fr']['title'] ?? 'Sans titre') ?></td>
                    <td class="colonne-actions">
                        <form method="post"
                              action="<?= attr($base . '/admin/rubriques/' . $rubrique['id'] . '/series/' . $serie['id'] . '/suppression') ?>"
                              data-confirmation="Supprimer cette série ? Les œuvres qu’elle regroupe seront conservées.">
                            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                            <button type="submit" class="lien-bouton">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <form method="post" action="<?= attr($base . '/admin/rubriques/' . $rubrique['id'] . '/series') ?>"
              class="formulaire">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <div class="grille-champs">
                <p class="champ">
                    <label for="titre_serie_fr">Nouvelle série (français)</label>
                    <input type="text" id="titre_serie_fr" name="titre_fr" maxlength="160">
                </p>
                <p class="champ">
                    <label for="titre_serie_en">Nouvelle série (anglais)</label>
                    <input type="text" id="titre_serie_en" name="titre_en" maxlength="160">
                </p>
            </div>
            <p class="actions">
                <button type="submit" class="bouton bouton--secondaire">Ajouter la série</button>
            </p>
        </form>
    </section>
    <?php endif; ?>
</div>
