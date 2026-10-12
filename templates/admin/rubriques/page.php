<?php

/**
 * Boutique › Galeries › Page « Galerie » (compte rendu client du 2026-10-12) :
 * texte explicatif affiché sous le titre de la page qui liste les galeries.
 *
 * Texte simple : rien n'est interprété, tout est échappé à l'affichage.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Editorial\GalleryPage;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var array{fr: array{heading: string, text: string}, en: array{heading: string, text: string}} $page */
$page = $data['page'];
?>
<div class="admin-page admin-page--etroite">
    <p class="fil-admin"><a href="<?= attr($base . '/admin/galeries') ?>">← Galeries</a></p>
    <h1>Page « Galerie »</h1>

    <p class="aide">
        Le texte explicatif affiché sous le titre de la page
        <a href="<?= attr($url->route('gallery.index', ['locale' => 'fr'])) ?>" target="_blank" rel="noopener">Galerie</a>,
        au-dessus de la liste des galeries. Laissé vide, la page garde sa phrase d’introduction par défaut.
    </p>

    <?php if (($data['enregistre'] ?? false) === true) : ?>
    <p class="succes" role="status">Le texte de la page Galerie a été enregistré.</p>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/galeries/page') ?>" class="formulaire" data-surveiller>
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <?php foreach (['fr' => 'Français', 'en' => 'Anglais'] as $langue => $libelle) : ?>
        <fieldset>
            <legend><?= e($libelle) ?><?php if ($langue === 'en') : ?> (vide : le français est repris)<?php endif; ?></legend>
            <p class="champ">
                <label for="titre_<?= attr($langue) ?>">Titre du texte</label>
                <input type="text" id="titre_<?= attr($langue) ?>" name="titre_<?= attr($langue) ?>"
                       value="<?= attr($page[$langue]['heading']) ?>" maxlength="<?= attr(GalleryPage::HEADING_MAX) ?>">
            </p>
            <p class="champ">
                <label for="texte_<?= attr($langue) ?>">Texte</label>
                <textarea id="texte_<?= attr($langue) ?>" name="texte_<?= attr($langue) ?>" rows="8"
                          maxlength="<?= attr(GalleryPage::TEXT_MAX) ?>"><?= e($page[$langue]['text']) ?></textarea>
                <?php if ($langue === 'fr') : ?>
                <span class="champ-aide">
                    Texte simple. Laissez une ligne vide entre deux paragraphes ; un simple retour à la ligne
                    reste un retour à la ligne.
                </span>
                <?php endif; ?>
            </p>
        </fieldset>
        <?php endforeach; ?>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
            <a class="bouton bouton--secondaire" href="<?= attr($base . '/admin/galeries') ?>">Retour aux galeries</a>
        </p>
    </form>
</div>
