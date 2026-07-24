<?php

/**
 * Boîte de réception des messages (04-back-office §10).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Contact\ContactMessage;
use App\Domain\Contact\MessageStatus;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';

/** @var list<ContactMessage> $messages */
$messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
/** @var MessageStatus|null $filtre */
$filtre = $data['filtre'] ?? null;
/** @var array<string, int> $compte */
$compte = is_array($data['compte'] ?? null) ? $data['compte'] : [];

$libelleStatut = [
    'new' => 'Nouveau',
    'read' => 'Lu',
    'answered' => 'Répondu',
    'spam' => 'Indésirable',
];

$onglets = [
    ['statut' => null, 'libelle' => 'Tous', 'cle' => 'tous'],
    ['statut' => 'new', 'libelle' => 'Nouveaux', 'cle' => 'new'],
    ['statut' => 'answered', 'libelle' => 'Répondus', 'cle' => 'answered'],
    ['statut' => 'spam', 'libelle' => 'Indésirables', 'cle' => 'spam'],
];

$courant = $filtre?->value;
?>
<div class="admin-page">
    <h1>Messages</h1>

    <nav class="onglets" aria-label="Filtrer par statut">
        <?php foreach ($onglets as $onglet) : ?>
            <?php
            $actif = $onglet['statut'] === $courant;
            $lien = $onglet['statut'] === null
                ? $base . '/admin/messages'
                : $base . '/admin/messages?statut=' . $onglet['statut'];
            ?>
            <a href="<?= attr($lien) ?>"<?php if ($actif) : ?> aria-current="page"<?php endif; ?>>
                <?= e($onglet['libelle']) ?> (<?= e($compte[$onglet['cle']] ?? 0) ?>)
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($messages === []) : ?>
    <p class="aide">Aucun message dans cette vue.</p>
    <?php else : ?>
    <table class="tableau">
        <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Expéditeur</th>
                <th scope="col">Sujet</th>
                <th scope="col">État</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($messages as $message) : ?>
            <tr<?php if ($message->status === MessageStatus::New) : ?> class="non-lu"<?php endif; ?>>
                <td><?= e($message->createdAt?->format('d/m/Y') ?? '') ?></td>
                <td><?= e($message->senderName) ?></td>
                <td>
                    <a href="<?= attr($base . '/admin/messages/' . $message->id) ?>">
                        <?= e($message->subject) ?>
                    </a>
                    <?php if ($message->isAboutArtwork()) : ?>
                    <span class="pastille">œuvre</span>
                    <?php endif; ?>
                </td>
                <td><?= e($libelleStatut[$message->status->value] ?? $message->status->value) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
