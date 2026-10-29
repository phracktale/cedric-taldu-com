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

use App\Domain\Editorial\BlockCatalog;

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

// Événement (2026-10-26) : statut, pays et entrée, que $champNeutre ne lit pas
// (valeur par défaut, nombre en centimes).
$statutEvenement = is_string($saisie['statut_evenement'] ?? null)
    ? $saisie['statut_evenement']
    : (is_string($article['event_status'] ?? null) ? $article['event_status'] : 'scheduled');
$paysEvenement = $champNeutre('pays_evenement', 'event_country');
$paysEvenement = $paysEvenement === '' ? 'FR' : $paysEvenement;
$entreeEvenement = is_string($saisie['entree_evenement'] ?? null)
    ? $saisie['entree_evenement']
    : (is_int($article['event_price_cents'] ?? null)
        ? str_replace('.', ',', rtrim(rtrim(number_format($article['event_price_cents'] / 100, 2, '.', ''), '0'), '.'))
        : '');

?>
<div class="admin-page admin-page--etroite">
    <h1><?= e($data['titre'] ?? '') ?></h1>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= attr($action) ?>" data-autosave<?php if ($article === null) : ?>="creation"<?php endif; ?> class="formulaire" data-surveiller
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

                    <p class="champ">
                        <label for="blocs_<?= attr($langue) ?>">Blocs après le corps (optionnel)</label>
                        <textarea id="blocs_<?= attr($langue) ?>" name="blocs_<?= attr($langue) ?>" rows="6"
                                  data-block-editor
                                  data-catalog="<?= jsonAttr(BlockCatalog::all()) ?>"
                                  data-presets="<?= jsonAttr(BlockCatalog::presets()) ?>"
                                  data-media-picker="<?= attr($base) ?>/admin/medias/choix"><?= e($valeur('blocs', $langue, 'blocks')) ?></textarea>
                        <span class="champ-aide">
                            Titres, images de la médiathèque, citations, boutons, colonnes… affichés sous le corps.
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
                    <label for="date_evenement">Date de début</label>
                    <input type="date" id="date_evenement" name="date_evenement"
                           value="<?= attr($champNeutre('date_evenement', 'event_date')) ?>">
                    <span class="champ-aide">Renseignée, l’article est présenté comme une exposition.</span>
                </p>
                <p class="champ">
                    <label for="date_fin">Date de fin</label>
                    <input type="date" id="date_fin" name="date_fin"
                           value="<?= attr($champNeutre('date_fin', 'event_end_date')) ?>">
                    <span class="champ-aide">Vide pour un événement d’un seul jour.</span>
                </p>
                <p class="champ">
                    <label for="lieu_evenement">Nom du lieu</label>
                    <input type="text" id="lieu_evenement" name="lieu_evenement" maxlength="200"
                           value="<?= attr($champNeutre('lieu_evenement', 'event_place')) ?>">
                    <span class="champ-aide">Nom de la galerie, du musée, du salon… sans l’adresse.</span>
                </p>
                <?php // 2026-10-26 : adresse en champs séparés, pour le balisage Event (PostalAddress). ?>
                <p class="champ">
                    <label for="rue_evenement">Rue</label>
                    <input type="text" id="rue_evenement" name="rue_evenement" maxlength="200"
                           value="<?= attr($champNeutre('rue_evenement', 'event_street')) ?>">
                </p>
                <p class="champ">
                    <label for="code_postal_evenement">Code postal</label>
                    <input type="text" id="code_postal_evenement" name="code_postal_evenement" maxlength="16"
                           value="<?= attr($champNeutre('code_postal_evenement', 'event_postal_code')) ?>">
                </p>
                <p class="champ">
                    <label for="ville_evenement">Ville</label>
                    <input type="text" id="ville_evenement" name="ville_evenement" maxlength="120"
                           value="<?= attr($champNeutre('ville_evenement', 'event_city')) ?>">
                </p>
                <p class="champ">
                    <label for="pays_evenement">Pays</label>
                    <input type="text" id="pays_evenement" name="pays_evenement" maxlength="2"
                           value="<?= attr($paysEvenement) ?>">
                    <span class="champ-aide">Deux lettres : FR pour la France, BE pour la Belgique…</span>
                </p>
                <p class="champ">
                    <label for="statut_evenement">Statut</label>
                    <select id="statut_evenement" name="statut_evenement">
                        <?php foreach (App\Domain\Editorial\EventStatus::cases() as $statut) : ?>
                        <option value="<?= attr($statut->value) ?>"<?php if ($statut->value === $statutEvenement) : ?> selected<?php endif; ?>><?= e($statut->adminLabel()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="champ">
                    <label for="organisateur_evenement">Organisateur</label>
                    <input type="text" id="organisateur_evenement" name="organisateur_evenement" maxlength="200"
                           value="<?= attr($champNeutre('organisateur_evenement', 'event_organizer')) ?>">
                </p>
                <p class="champ">
                    <label for="site_organisateur_evenement">Site de l’organisateur</label>
                    <input type="url" id="site_organisateur_evenement" name="site_organisateur_evenement" maxlength="500"
                           placeholder="https://"
                           value="<?= attr($champNeutre('site_organisateur_evenement', 'event_organizer_url')) ?>">
                </p>
                <p class="champ">
                    <label for="entree_evenement">Entrée (€)</label>
                    <input type="text" id="entree_evenement" name="entree_evenement" inputmode="decimal" maxlength="12"
                           value="<?= attr($entreeEvenement) ?>">
                    <span class="champ-aide">0 pour une entrée libre ; vide si vous ne savez pas.</span>
                </p>
                <p class="champ">
                    <label for="lien_evenement">Lien (site du lieu, billetterie…)</label>
                    <input type="url" id="lien_evenement" name="lien_evenement" maxlength="500"
                           placeholder="https://"
                           value="<?= attr($champNeutre('lien_evenement', 'event_url')) ?>">
                </p>
            </div>
            <?php foreach ($langues as $langue => $libelle) : ?>
            <p class="champ">
                <label for="description_evenement_<?= attr($langue) ?>">Description (<?= e($libelle) ?>)</label>
                <textarea id="description_evenement_<?= attr($langue) ?>"
                          name="description_evenement_<?= attr($langue) ?>" rows="3"
                          maxlength="2000"><?= e($valeur('description_evenement', $langue, 'event_description')) ?></textarea>
                <?php if ($langue === 'fr') : ?>
                <span class="champ-aide">Horaires, vernissage, œuvres présentées… Texte simple, affiché avec les dates.</span>
                <?php endif; ?>
            </p>
            <?php endforeach; ?>
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
