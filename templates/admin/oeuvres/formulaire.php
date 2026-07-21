<?php

/**
 * Creation et modification d'une œuvre.
 *
 * Les blocs suivent 04-back-office §5 : Identification, Contenu (par langue),
 * Caracteristiques, Commerce, Medias, Publication, SEO. L'ordre n'est pas
 * cosmetique — il suit celui dans lequel l'artiste connait les informations
 * quand il rentre de l'atelier.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\ArtworkStatus;
use App\Domain\Locale;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var array<string, mixed>|null $oeuvre */
$oeuvre = is_array($data['oeuvre'] ?? null) ? $data['oeuvre'] : null;

/** @var array<string, string> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];

/** @var list<array<string, mixed>> $rubriques */
$rubriques = is_array($data['rubriques'] ?? null) ? $data['rubriques'] : [];

/** @var list<array<string, mixed>> $series */
$series = is_array($data['series'] ?? null) ? $data['series'] : [];

$action = $oeuvre === null
    ? $base . '/admin/oeuvres'
    : $base . '/admin/oeuvres/' . $oeuvre['id'];

/** Valeur d'un champ simple : la saisie refusee prime sur la valeur en base. */
$champ = static function (string $nom, string $colonne = '') use ($saisie, $oeuvre): string {
    if (is_string($saisie[$nom] ?? null)) {
        return $saisie[$nom];
    }

    $cle = $colonne === '' ? $nom : $colonne;
    $valeur = $oeuvre[$cle] ?? null;

    return is_scalar($valeur) ? (string) $valeur : '';
};

/** Valeur d'un champ traduisible. */
$traduit = static function (string $nom, string $langue, string $colonne) use ($saisie, $oeuvre): string {
    if (is_string($saisie[$nom . '_' . $langue] ?? null)) {
        return $saisie[$nom . '_' . $langue];
    }

    $traduction = $oeuvre['translations'][$langue] ?? [];

    return is_array($traduction) && is_string($traduction[$colonne] ?? null) ? $traduction[$colonne] : '';
};

/** Le prix est reaffiche EN EUROS, comme il a ete saisi. */
$prix = static function () use ($saisie, $oeuvre): string {
    if (is_string($saisie['prix'] ?? null)) {
        return $saisie['prix'];
    }

    $cents = $oeuvre['price_cents'] ?? null;

    return is_int($cents) ? number_format($cents / 100, 2, '.', '') : '';
};

$langues = ['fr' => 'Français', 'en' => 'English'];

?>
<div class="admin-page">
    <h1><?= e($data['titre'] ?? '') ?></h1>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <?php if (is_string($data['apercu'] ?? null)) : ?>
    <p class="aide">
        <a href="<?= attr($data['apercu']) ?>">Aperçu de la fiche publique</a> —
        lien signé, valable 24 heures, y compris si l’œuvre n’est pas publiée.
    </p>
    <?php endif; ?>

    <form method="post" action="<?= attr($action) ?>" class="formulaire" data-surveiller>
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <fieldset>
            <legend>Identification</legend>

            <div class="grille-champs">
                <p class="champ">
                    <label for="reference">Référence d’atelier (obligatoire)</label>
                    <input type="text" id="reference" name="reference"
                           value="<?= attr($champ('reference')) ?>" maxlength="40" required>
                </p>

                <p class="champ">
                    <label for="annee">Année</label>
                    <input type="number" id="annee" name="annee" min="1900" max="2200"
                           value="<?= attr($champ('annee', 'year')) ?>">
                </p>
            </div>

            <div class="grille-champs">
                <p class="champ">
                    <label for="rubrique">Rubrique (obligatoire)</label>
                    <select id="rubrique" name="rubrique" required>
                        <option value="">Choisir…</option>
                        <?php foreach ($rubriques as $rubrique) : ?>
                        <option value="<?= attr($rubrique['id']) ?>"
                            <?php if ((string) $champ('rubrique', 'category_id') === (string) $rubrique['id']) : ?>selected<?php endif; ?>
                        ><?= e($rubrique['translations']['fr']['title'] ?? 'Sans titre') ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="champ">
                    <label for="serie">Série</label>
                    <select id="serie" name="serie">
                        <option value="">Sans série</option>
                        <?php foreach ($series as $serie) : ?>
                        <option value="<?= attr($serie['id']) ?>"
                            <?php if ((string) $champ('serie', 'series_id') === (string) $serie['id']) : ?>selected<?php endif; ?>
                        ><?= e($serie['translations']['fr']['title'] ?? 'Sans titre') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="champ-aide">Les séries se créent depuis la rubrique.</span>
                </p>
            </div>
        </fieldset>

        <div data-onglets-langue>
        <?php foreach ($langues as $langue => $libelle) : ?>
            <section class="panneau-langue" data-langue="<?= attr($langue) ?>" data-libelle="<?= attr($libelle) ?>">
                <fieldset>
                    <legend>Contenu — <?= e($libelle) ?></legend>

                    <p class="champ">
                        <label for="titre_<?= attr($langue) ?>">Titre<?php if ($langue === 'fr') : ?> (obligatoire)<?php endif; ?></label>
                        <input type="text" id="titre_<?= attr($langue) ?>" name="titre_<?= attr($langue) ?>"
                               value="<?= attr($traduit('titre', $langue, 'title')) ?>" maxlength="200">
                    </p>

                    <p class="champ">
                        <label for="slug_<?= attr($langue) ?>">Identifiant d’URL</label>
                        <input type="text" id="slug_<?= attr($langue) ?>" name="slug_<?= attr($langue) ?>"
                               value="<?= attr($traduit('slug', $langue, 'slug')) ?>" maxlength="190"
                               data-slug-depuis="titre_<?= attr($langue) ?>">
                    </p>

                    <p class="champ">
                        <label for="surtitre_<?= attr($langue) ?>">Surtitre</label>
                        <input type="text" id="surtitre_<?= attr($langue) ?>" name="surtitre_<?= attr($langue) ?>"
                               value="<?= attr($traduit('surtitre', $langue, 'eyebrow')) ?>" maxlength="160">
                    </p>

                    <p class="champ">
                        <label for="description_<?= attr($langue) ?>">Description</label>
                        <textarea id="description_<?= attr($langue) ?>"
                                  name="description_<?= attr($langue) ?>"><?= e($traduit('description', $langue, 'description')) ?></textarea>
                    </p>

                    <p class="champ">
                        <label for="detail_<?= attr($langue) ?>">Détail</label>
                        <textarea id="detail_<?= attr($langue) ?>"
                                  name="detail_<?= attr($langue) ?>"><?= e($traduit('detail', $langue, 'detail')) ?></textarea>
                    </p>

                    <div class="grille-champs">
                        <p class="champ">
                            <label for="meta_titre_<?= attr($langue) ?>">Titre pour les moteurs</label>
                            <input type="text" id="meta_titre_<?= attr($langue) ?>"
                                   name="meta_titre_<?= attr($langue) ?>"
                                   value="<?= attr($traduit('meta_titre', $langue, 'meta_title')) ?>" maxlength="180">
                        </p>

                        <p class="champ">
                            <label for="meta_description_<?= attr($langue) ?>">Description pour les moteurs</label>
                            <input type="text" id="meta_description_<?= attr($langue) ?>"
                                   name="meta_description_<?= attr($langue) ?>"
                                   value="<?= attr($traduit('meta_description', $langue, 'meta_description')) ?>"
                                   maxlength="300">
                        </p>
                    </div>
                </fieldset>
            </section>
        <?php endforeach; ?>
        </div>

        <fieldset>
            <legend>Caractéristiques</legend>

            <p class="champ">
                <label for="technique">Technique</label>
                <input type="text" id="technique" name="technique"
                       value="<?= attr($champ('technique')) ?>" maxlength="160"
                       placeholder="Encre de Chine sur papier">
            </p>

            <div class="grille-champs">
                <p class="champ">
                    <label for="largeur">Largeur (mm)</label>
                    <input type="number" id="largeur" name="largeur" min="1"
                           value="<?= attr($champ('largeur', 'width_mm')) ?>">
                </p>

                <p class="champ">
                    <label for="hauteur">Hauteur (mm)</label>
                    <input type="number" id="hauteur" name="hauteur" min="1"
                           value="<?= attr($champ('hauteur', 'height_mm')) ?>">
                </p>

                <p class="champ">
                    <label for="poids">Poids (g)</label>
                    <input type="number" id="poids" name="poids" min="1"
                           value="<?= attr($champ('poids', 'weight_grams')) ?>">
                    <span class="champ-aide">Sert au calcul des frais de port.</span>
                </p>
            </div>

            <p class="champ champ-inline">
                <input type="checkbox" id="signee" name="signee" value="1"
                    <?php if (($oeuvre['is_signed'] ?? true) === true) : ?>checked<?php endif; ?>>
                <label for="signee">Signée</label>
            </p>
        </fieldset>

        <fieldset>
            <legend>Commerce</legend>

            <div class="grille-champs">
                <p class="champ">
                    <label for="prix">Prix TTC (euros)</label>
                    <input type="text" id="prix" name="prix" inputmode="decimal"
                           value="<?= attr($prix()) ?>" placeholder="450,00">
                    <span class="champ-aide">Vide : l’œuvre n’est pas vendable en ligne.</span>
                </p>

                <p class="champ">
                    <label for="statut">Statut</label>
                    <select id="statut" name="statut">
                        <?php foreach (ArtworkStatus::cases() as $statut) : ?>
                        <option value="<?= attr($statut->value) ?>"
                            <?php if ($champ('statut', 'status') === $statut->value) : ?>selected<?php endif; ?>
                        ><?= e($statut->label(Locale::Fr)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="champ-aide">« Disponible » exige un prix.</span>
                </p>

                <p class="champ">
                    <label for="tva">Catégorie de TVA</label>
                    <select id="tva" name="tva">
                        <option value="original_artwork"
                            <?php if ($champ('tva', 'vat_category') !== 'standard_goods') : ?>selected<?php endif; ?>
                        >Œuvre originale — 5,5 %</option>
                        <option value="original_print"
                            <?php if ($champ('tva', 'vat_category') === 'original_print') : ?>selected<?php endif; ?>
                        >Estampe originale — 5,5 %</option>
                        <option value="standard_goods"
                            <?php if ($champ('tva', 'vat_category') === 'standard_goods') : ?>selected<?php endif; ?>
                        >Reproduction — 20 %</option>
                    </select>
                    <span class="champ-aide">
                        Un tirage giclée, même signé, numéroté et rehaussé, reste une reproduction
                        photomécanique au sens fiscal.
                    </span>
                </p>
            </div>
        </fieldset>

        <fieldset>
            <legend>Média</legend>

            <p class="champ">
                <label for="image_principale">Image principale</label>
                <input type="number" id="image_principale" name="image_principale" min="1"
                       value="<?= attr($champ('image_principale', 'primary_media_id')) ?>">
                <span class="champ-aide">
                    Le numéro affiché dans la <a href="<?= attr($base . '/admin/medias') ?>">médiathèque</a>.
                    Sans image principale, l’œuvre ne peut pas être publiée.
                </span>
            </p>
        </fieldset>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
            <a class="bouton bouton--secondaire" href="<?= attr($base . '/admin/oeuvres') ?>">Retour à la liste</a>
        </p>
    </form>

    <?php if ($oeuvre !== null) : ?>
    <div class="actions">
        <form method="post" action="<?= attr($base . '/admin/oeuvres/' . $oeuvre['id'] . '/publication') ?>">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <button type="submit" class="bouton bouton--secondaire">
                <?php if ($oeuvre['is_published'] === true) : ?>Dépublier<?php else : ?>Publier<?php endif; ?>
            </button>
        </form>

        <form method="post" action="<?= attr($base . '/admin/oeuvres/' . $oeuvre['id'] . '/suppression') ?>"
              data-confirmation="Supprimer définitivement cette œuvre ?">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <button type="submit" class="lien-bouton">Supprimer</button>
        </form>
    </div>
    <?php endif; ?>
</div>
