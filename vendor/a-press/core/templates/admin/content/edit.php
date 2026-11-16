<?php

/**
 * Éditeur d'une entrée, façon WordPress : les champs à gauche, le panneau
 * « Publier » à droite. Les listes affichent toujours une ligne vide de plus
 * pour ajouter sans JavaScript ; admin.js ajoute « Ajouter une ligne »,
 * « Retirer » et le réordonnancement quand il est disponible.
 *
 * @var array<string, mixed>              $data
 * @var APress\Core\Ui\UrlBuilder          $url
 * @var class-string<APress\Core\Ui\Html> $h
 * @var callable                          $partial
 */

declare(strict_types=1);

use APress\Core\Content\ChildDefinition;
use APress\Core\Content\ContentType;
use APress\Core\Content\Entry;
use APress\Core\Content\EntryStatus;

$type = $data['type'];
assert($type instanceof ContentType);
$entree = ($data['entree'] ?? null) instanceof Entry ? $data['entree'] : null;
/** @var array<string, mixed> $valeurs */
$valeurs = is_array($data['valeurs'] ?? null) ? $data['valeurs'] : [];
/** @var array<string, string> $erreurs */
$erreurs = is_array($data['erreurs'] ?? null) ? $data['erreurs'] : [];
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$apercu = ($data['apercu'] ?? false) === true;
/** @var list<string> $panneaux HTML déjà échappé par chaque extension */
$panneaux = is_array($data['panneaux'] ?? null) ? $data['panneaux'] : [];
$base = 'contenu/' . $type->name . ($entree !== null ? '/' . $entree->id : '');
$lectureSeule = $entree !== null && ($entree->received || !$type->canEdit);

/**
 * Lignes d'une liste (et de ses sous-listes pour un arbre), plus une vide.
 *
 * @param list<mixed> $lignes
 */
$liste = static function (ChildDefinition $definition, string $prefixe, array $lignes, int $profondeur) use (&$liste, $partial, $erreurs, $h): string {
    $html = '';
    $lignes[] = [];
    foreach (array_values($lignes) as $i => $ligne) {
        $ligne = is_array($ligne) ? $ligne : [];
        $html .= '<li class="ligne" data-ligne>';
        foreach ($definition->fields as $nom => $champ) {
            $chemin = $prefixe . '--' . $i . '--' . $nom;
            $html .= $partial('admin/content/field', [
                'field' => $champ,
                'name' => $chemin,
                'value' => $ligne[$nom] ?? null,
                'error' => $erreurs[str_replace('--', '.', $chemin)] ?? null,
                // Pas de `required` dans une liste : la ligne vide « pour
                // ajouter » bloquerait l'envoi. Le serveur valide les lignes
                // remplies et ignore les vides.
                'inList' => true,
            ]);
        }
        if ($definition->tree && $profondeur < 2) {
            $html .= '<details class="sous-liste"><summary>Sous-éléments</summary><ol data-liste>'
                . $liste($definition, $prefixe . '--' . $i . '--children', is_array($ligne['children'] ?? null) ? $ligne['children'] : [], $profondeur + 1)
                . '</ol></details>';
        }
        $html .= '</li>';
    }

    return $html;
};
?>
<h1><?= $h::e($entree === null ? 'Nouveau : ' . $type->singular : $type->singular) ?></h1>
<?php if (!$type->single) : ?>
<p><a href="<?= $h::attr($url->admin('contenu/' . $type->name)) ?>">← <?= $h::e($type->label) ?></a></p>
<?php endif; ?>

<?php if ($erreurs !== []) : ?>
<p class="notice notice-erreur" role="alert">La saisie comporte des erreurs : rien n’a été enregistré.</p>
<?php endif; ?>

<form method="post" action="<?= $h::attr($url->admin($base)) ?>" class="editeur" data-editeur>
    <input type="hidden" name="_token" value="<?= $h::attr($jeton) ?>">

    <div class="editeur-corps">
        <?php foreach ($type->fields as $nom => $champ) : ?>
            <?php if ($champ->type === 'embed') : ?>
        <fieldset class="postbox">
            <legend><?= $h::e($champ->label) ?></legend>
                <?php foreach ($champ->fields as $sousNom => $sousChamp) : ?>
                    <?= $partial('admin/content/field', [
                        'field' => $sousChamp,
                        'name' => $nom . '--' . $sousNom,
                        'value' => is_array($valeurs[$nom] ?? null) ? ($valeurs[$nom][$sousNom] ?? null) : null,
                        'error' => $erreurs[$nom . '.' . $sousNom] ?? null,
                    ]) ?>
                <?php endforeach; ?>
        </fieldset>
            <?php else : ?>
                <?= $partial('admin/content/field', [
                    'field' => $champ,
                    'name' => $nom,
                    'value' => $valeurs[$nom] ?? null,
                    'error' => $erreurs[$nom] ?? null,
                    'entryId' => $entree?->id,
                ]) ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php foreach ($type->children as $nom => $enfant) : ?>
        <fieldset class="postbox liste">
            <legend><?= $h::e($enfant->label) ?></legend>
            <ol data-liste>
                <?= $liste($enfant, $nom, is_array($valeurs[$nom] ?? null) ? $valeurs[$nom] : [], 0) ?>
            </ol>
            <p class="description">Une ligne laissée vide est ignorée.</p>
        </fieldset>
        <?php endforeach; ?>
    </div>

    <aside class="editeur-cote postbox" aria-label="Publier">
        <h2>Publier</h2>
        <?php if ($entree !== null) : ?>
        <p>État : <strong><?= $h::e($entree->status()->label()) ?></strong></p>
            <?php if ($entree->publishedAt !== null) : ?>
        <p class="description">En ligne depuis le <?= $h::e($entree->publishedAt) ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$lectureSeule) : ?>
        <p class="actions">
            <button type="submit" name="_action" value="save" class="bouton bouton--secondaire">
                <?= $h::e($type->publishable ? 'Enregistrer le brouillon' : 'Enregistrer') ?>
            </button>
            <?php if ($type->publishable) : ?>
            <button type="submit" name="_action" value="publish" class="bouton">Publier</button>
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if ($apercu) : ?>
        <p><a class="bouton bouton--secondaire" href="<?= $h::attr($url->admin($base . '/apercu')) ?>" target="_blank" rel="noopener">Prévisualiser</a></p>
        <?php endif; ?>

        <?php if ($entree !== null) : ?>
        <p><a href="<?= $h::attr($url->admin($base . '/historique')) ?>">Historique des versions</a></p>
        <?php endif; ?>

        <?php foreach ($panneaux as $panneau) : ?>
        <div class="panneau-extension"><?= $panneau ?></div>
        <?php endforeach; ?>
    </aside>
</form>

<?php if ($entree !== null) : ?>
<div class="actions-secondaires">
    <?php if ($type->publishable && in_array($entree->status(), [EntryStatus::Published, EntryStatus::Changed], true)) : ?>
    <form method="post" action="<?= $h::attr($url->admin($base . '/depublier')) ?>">
        <input type="hidden" name="_token" value="<?= $h::attr($jeton) ?>">
        <button type="submit" class="lien-bouton">Retirer du site</button>
    </form>
    <?php endif; ?>
    <?php if (!$type->single && $entree->trashedAt === null) : ?>
    <form method="post" action="<?= $h::attr($url->admin($base . '/corbeille')) ?>">
        <input type="hidden" name="_token" value="<?= $h::attr($jeton) ?>">
        <button type="submit" class="lien-bouton lien-danger">Mettre à la corbeille</button>
    </form>
    <?php elseif ($entree->trashedAt !== null) : ?>
    <form method="post" action="<?= $h::attr($url->admin($base . '/restaurer')) ?>">
        <input type="hidden" name="_token" value="<?= $h::attr($jeton) ?>">
        <button type="submit" class="lien-bouton">Sortir de la corbeille</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
