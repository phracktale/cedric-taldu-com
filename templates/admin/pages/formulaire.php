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

    <form method="post" action="<?= attr($action) ?>" class="formulaire" data-surveiller>
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

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
            <a class="bouton bouton--secondaire" href="<?= attr($base . '/admin/pages') ?>">Retour à la liste</a>
        </p>
    </form>
</div>
