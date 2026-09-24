<?php

/**
 * Abonnés à la newsletter (revue du 2026-09-24).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<array{email: string, locale: string, source: string, consent_text: string, consented_at: string}> $abonnes */
$abonnes = is_array($data['abonnes'] ?? null) ? $data['abonnes'] : [];
$sources = ['contact' => 'Formulaire de contact', 'checkout' => 'Commande', 'account' => 'Compte client'];
?>
<div class="admin-page">
    <h1>Newsletter</h1>

    <p class="aide">
        <?= e((string) count($abonnes)) ?> abonné(s). L’envoi se fait depuis votre outil d’e-mailing :
        exportez la liste, elle contient pour chaque abonné son lien de désinscription, à placer dans
        chaque message.
    </p>

    <p class="actions">
        <a class="bouton" href="<?= attr($base . '/admin/newsletter/export') ?>">Exporter (CSV)</a>
    </p>

    <?php if ($abonnes !== []) : ?>
    <table class="tableau">
        <thead>
            <tr>
                <th scope="col">Adresse</th>
                <th scope="col">Langue</th>
                <th scope="col">Inscription</th>
                <th scope="col">Consentement</th>
                <th scope="col" class="colonne-actions">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($abonnes as $abonne) : ?>
            <tr>
                <td><?= e($abonne['email']) ?></td>
                <td><?= e(strtoupper($abonne['locale'])) ?></td>
                <td><?= e($sources[$abonne['source']] ?? $abonne['source']) ?></td>
                <td><?= e($abonne['consented_at']) ?> UTC</td>
                <td class="colonne-actions">
                    <form method="post" action="<?= attr($base . '/admin/newsletter/desinscription') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <input type="hidden" name="email" value="<?= attr($abonne['email']) ?>">
                        <button type="submit" class="bouton bouton--secondaire">Désinscrire</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
