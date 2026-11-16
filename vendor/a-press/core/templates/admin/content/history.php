<?php

/**
 * Historique d'une entrée : chaque version publiée, et le brouillon en cours.
 * Restaurer recopie une version dans le brouillon, sans toucher au site.
 *
 * @var array<string, mixed>              $data
 * @var APress\Core\Ui\UrlBuilder          $url
 * @var class-string<APress\Core\Ui\Html> $h
 */

declare(strict_types=1);

use APress\Core\Content\ContentType;
use APress\Core\Content\Entry;
use APress\Core\Content\Revision;

$type = $data['type'];
$entree = $data['entree'];
assert($type instanceof ContentType && $entree instanceof Entry);
/** @var list<Revision> $revisions */
$revisions = is_array($data['revisions'] ?? null) ? $data['revisions'] : [];
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$base = 'contenu/' . $type->name . '/' . $entree->id;
$champTitre = $type->titleField();
?>
<h1>Historique</h1>
<p><a href="<?= $h::attr($url->admin($base)) ?>">← Retour à l’éditeur</a></p>

<table class="tableau">
    <thead><tr><th scope="col">Version</th><th scope="col">Date</th><th scope="col">Titre</th><th scope="col">Action</th></tr></thead>
    <tbody>
        <?php foreach ($revisions as $revision) : ?>
        <tr>
            <td><?= $h::e($revision->publishedAt !== null ? 'Publiée' : 'Brouillon') ?></td>
            <td><?= $h::e($revision->publishedAt ?? $revision->updatedAt) ?></td>
            <td><?= $h::e(is_scalar($revision->data[$champTitre] ?? null) ? (string) $revision->data[$champTitre] : '') ?></td>
            <td>
                <?php if ($revision->publishedAt !== null) : ?>
                <form method="post" action="<?= $h::attr($url->admin($base . '/historique/' . $revision->id . '/restaurer')) ?>">
                    <input type="hidden" name="_token" value="<?= $h::attr($jeton) ?>">
                    <button type="submit" class="lien-bouton">Restaurer dans le brouillon</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
