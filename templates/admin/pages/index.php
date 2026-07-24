<?php

/**
 * Liste des pages éditoriales à code fixe (04-back-office §9).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var list<array<string, mixed>> $pages */
$pages = is_array($data['pages'] ?? null) ? $data['pages'] : [];

$libelle = [
    'about' => 'À propos',
    'booklet' => 'Livret',
    'legal' => 'Mentions légales',
    'privacy' => 'Confidentialité',
    'terms' => 'Conditions générales de vente',
];

$reglementaires = ['legal', 'privacy', 'terms'];
?>
<div class="admin-page">
    <h1>Pages</h1>

    <p class="aide">Les pages à code fixe ne se créent ni ne se suppriment : on en édite le contenu.</p>

    <table class="tableau">
        <thead>
            <tr>
                <th scope="col">Page</th>
                <th scope="col">État</th>
                <th scope="col" class="colonne-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pages as $page) : ?>
            <?php
            $code = (string) $page['code'];
            $publie = ($page['is_published'] ?? false) === true;
            $verrouillee = in_array($code, $reglementaires, true);
            ?>
            <tr>
                <td>
                    <a href="<?= attr($base . '/admin/pages/' . $page['id']) ?>"><?= e($libelle[$code] ?? $code) ?></a>
                </td>
                <td>
                    <?php if ($publie) : ?>
                    <span class="pastille pastille--publie">Publiée</span>
                    <?php else : ?>
                    <span class="pastille pastille--brouillon">Non publiée</span>
                    <?php endif; ?>
                </td>
                <td class="colonne-actions">
                    <?php if (!$verrouillee) : ?>
                    <form method="post" action="<?= attr($base . '/admin/pages/' . $page['id'] . '/publication') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="lien-bouton">
                            <?php if ($publie) : ?>Dépublier<?php else : ?>Publier<?php endif; ?>
                        </button>
                    </form>
                    <?php else : ?>
                    <span class="champ-aide">Toujours accessible</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
