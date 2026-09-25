<?php

/**
 * Édition d'un bloc réutilisable (retours du 2026-09-25) : nom, puis contenu
 * et design par langue dans l'éditeur de blocs. Sans traduction anglaise, le
 * contenu français est rendu.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Editorial\BlockCatalog;
use App\Domain\Editorial\ContentBlock;
use App\Domain\Locale;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var ContentBlock $bloc */
$bloc = $data['bloc'];
$langues = ['fr' => 'Français', 'en' => 'Anglais'];
?>
<div class="admin-page">
    <p><a href="<?= attr($base . '/admin/blocs') ?>">← Tous les blocs</a></p>
    <h1><?= e($bloc->name) ?></h1>

    <p class="aide">
        Composez le bloc avec « + Ajouter » : un bloc simple, ou un modèle de section (bannière,
        colonnes, texte + image…). Chaque bloc règle son contenu et son design — image de fond,
        couleur, alignement, hauteur. Placez-le ensuite dans l’accueil ou un template
        (clef <code><?= e($bloc->key()) ?></code>).
    </p>

    <form method="post" action="<?= attr($base . '/admin/blocs/' . $bloc->id) ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="nom">Nom</label>
            <input type="text" id="nom" name="nom" maxlength="120" required value="<?= attr($bloc->name) ?>">
        </p>

        <div data-onglets-langue>
        <?php foreach ($langues as $langue => $libelle) : ?>
            <section class="panneau-langue" data-langue="<?= attr($langue) ?>" data-libelle="<?= attr($libelle) ?>">
                <fieldset>
                    <legend><?= e($libelle) ?></legend>
                    <p class="champ">
                        <label for="blocs_<?= attr($langue) ?>">Contenu<?php if ($langue === 'en') : ?> (vide : le français est affiché)<?php endif; ?></label>
                        <textarea id="blocs_<?= attr($langue) ?>" name="blocs_<?= attr($langue) ?>" rows="8"
                                  data-block-editor
                                  data-catalog="<?= jsonAttr(BlockCatalog::all()) ?>"
                                  data-presets="<?= jsonAttr(BlockCatalog::presets()) ?>"
                                  data-media-picker="<?= attr($base) ?>/admin/medias/choix"><?= e($bloc->rawJson(Locale::from($langue))) ?></textarea>
                    </p>
                </fieldset>
            </section>
        <?php endforeach; ?>
        </div>

        <p class="actions"><button type="submit" class="bouton">Enregistrer le bloc</button></p>
    </form>

    <form method="post" action="<?= attr($base . '/admin/blocs/' . $bloc->id . '/suppression') ?>"
          data-confirmation="Supprimer ce bloc ? Il disparaîtra des pages où il est placé.">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
        <button type="submit" class="lien-bouton lien-danger">Supprimer le bloc</button>
    </form>
</div>
