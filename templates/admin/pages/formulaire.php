<?php

/**
 * Édition d'une page à code fixe (04-back-office §9).
 *
 * Le slug n'est pas éditable : les routes en dépendent. On édite le titre, le
 * corps (assaini à l'enregistrement) et le SEO, par langue.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Editorial\BlockCatalog;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var array<string, mixed> $page */
$page = is_array($data['page'] ?? null) ? $data['page'] : [];

/** @var array<string, string> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];

$action = $base . '/admin/pages/' . ($page['id'] ?? '');

$valeur = static function (string $champ, string $langue, string $colonne) use ($saisie, $page): string {
    $poste = $saisie[$champ . '_' . $langue] ?? null;

    if (is_string($poste)) {
        return $poste;
    }

    $traduction = $page['translations'][$langue] ?? [];

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
                               value="<?= attr($valeur('titre', $langue, 'title')) ?>" maxlength="220">
                    </p>

                    <p class="champ">
                        <label for="corps_<?= attr($langue) ?>">Contenu</label>
                        <textarea id="corps_<?= attr($langue) ?>" name="corps_<?= attr($langue) ?>"
                                  rows="16"><?= e($valeur('corps', $langue, 'body')) ?></textarea>
                        <span class="champ-aide">
                            Titres &lt;h2&gt;/&lt;h3&gt;, &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, listes, citations, liens,
                            images. Tout le reste est retiré à l’enregistrement.
                        </span>
                    </p>

                    <p class="champ">
                        <label for="blocs_<?= attr($langue) ?>">Composition par blocs (optionnel)</label>
                        <textarea id="blocs_<?= attr($langue) ?>" name="blocs_<?= attr($langue) ?>" rows="6"
                                  data-block-editor
                                  data-catalog="<?= jsonAttr(BlockCatalog::all()) ?>"
                                  data-media-base="<?= attr($base) ?>/admin/medias"><?= e($valeur('blocs', $langue, 'blocks')) ?></textarea>
                        <span class="champ-aide">
                            Dès qu’un bloc est défini, la composition par blocs REMPLACE le contenu HTML ci-dessus
                            sur la page publique. Sans JavaScript, ce champ contient le JSON brut des blocs.
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
                       value="<?= attr($page['cover_media_id'] ?? '') ?>">
                <span class="champ-aide">
                    Le numéro affiché dans la <a href="<?= attr($base . '/admin/medias') ?>">médiathèque</a>.
                    Un fichier téléversé a la priorité.
                </span>
            </p>
        </fieldset>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
            <a class="bouton bouton--secondaire" href="<?= attr($base . '/admin/pages') ?>">Retour à la liste</a>
        </p>
    </form>
</div>
