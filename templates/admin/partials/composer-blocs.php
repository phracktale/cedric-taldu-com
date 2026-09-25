<?php

/**
 * Groupe « Blocs » d'une palette de compositeur (retours du 2026-09-25) : les
 * blocs de la bibliothèque, puis les modèles à partir desquels un nouveau bloc
 * est créé à l'enregistrement. Commun à l'accueil et aux templates.
 *
 * @var array<string, mixed> $data
 * @var callable             $partial
 */

declare(strict_types=1);

/** @var array<int, string> $bibliotheque */
$bibliotheque = is_array($data['bibliotheque'] ?? null) ? $data['bibliotheque'] : [];
/** @var array<string, array{label: string}> $modeles */
$modeles = is_array($data['modeles'] ?? null) ? $data['modeles'] : [];
$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
?>
<h2>Blocs</h2>
<ul>
    <?php foreach ($bibliotheque as $id => $nom) : ?>
    <?= $partial('admin/partials/composer-source', ['item' => ['type' => 'block', 'ref' => (string) $id], 'label' => 'Bloc : ' . $nom]) ?>
    <?php endforeach; ?>
    <?php foreach ($modeles as $cle => $modele) : ?>
    <?= $partial('admin/partials/composer-source', ['item' => ['type' => 'new', 'ref' => $cle], 'label' => 'Nouveau bloc : ' . $modele['label']]) ?>
    <?php endforeach; ?>
</ul>
<p class="champ-aide"><a href="<?= attr($base . '/admin/blocs') ?>">Gérer la bibliothèque de blocs</a></p>
