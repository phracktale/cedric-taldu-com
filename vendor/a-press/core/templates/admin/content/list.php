<?php

/**
 * Liste des entrées d'un type, façon « wp-list-table » : titre, état, date.
 *
 * @var array<string, mixed>              $data
 * @var APress\Core\Ui\UrlBuilder          $url
 * @var class-string<APress\Core\Ui\Html> $h
 */

declare(strict_types=1);

use APress\Core\Content\ContentType;
use APress\Core\Content\Entry;

$type = $data['type'];
assert($type instanceof ContentType);
/** @var list<Entry> $entrees */
$entrees = is_array($data['entrees'] ?? null) ? $data['entrees'] : [];
$corbeille = ($data['corbeille'] ?? false) === true;
$champTitre = $type->titleField();
?>
<h1 class="wp-heading-inline"><?= $h::e($type->label) ?></h1>
<?php if ($type->canCreate) : ?>
<a class="bouton bouton--secondaire" href="<?= $h::attr($url->admin('contenu/' . $type->name . '/nouveau')) ?>">Ajouter</a>
<?php endif; ?>

<p class="filtres">
    <a href="<?= $h::attr($url->admin('contenu/' . $type->name)) ?>"<?php if (!$corbeille) : ?> aria-current="page"<?php endif; ?>>Tous</a>
    |
    <a href="<?= $h::attr($url->admin('contenu/' . $type->name) . '?corbeille=1') ?>"<?php if ($corbeille) : ?> aria-current="page"<?php endif; ?>>Corbeille</a>
</p>

<?php if ($entrees === []) : ?>
<p><?= $h::e($corbeille ? 'La corbeille est vide.' : 'Aucune entrée pour l’instant.') ?></p>
<?php else : ?>
<table class="tableau">
    <thead>
        <tr><th scope="col">Titre</th><th scope="col">État</th><th scope="col">Modifié</th></tr>
    </thead>
    <tbody>
        <?php foreach ($entrees as $entree) : ?>
            <?php $valeurs = $entree->current(); ?>
        <tr>
            <td>
                <a class="row-title" href="<?= $h::attr($url->admin('contenu/' . $type->name . '/' . $entree->id)) ?>">
                    <?= $h::e(is_scalar($valeurs[$champTitre] ?? null) && (string) $valeurs[$champTitre] !== '' ? (string) $valeurs[$champTitre] : '(sans titre)') ?>
                </a>
            </td>
            <td><span class="etat etat--<?= $h::attr($entree->status()->value) ?>"><?= $h::e($entree->status()->label()) ?></span></td>
            <td><?= $h::e($entree->updatedAt) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
