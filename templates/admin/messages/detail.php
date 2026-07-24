<?php

/**
 * Détail d'un message de contact (04-back-office §10).
 *
 * La réponse se fait par le client de messagerie de l'artiste : un lien
 * `mailto:` pré-rempli, jamais un envoi depuis le site. Le corps est du texte
 * brut, échappé et rendu avec ses sauts de ligne préservés.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Contact\ContactMessage;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var ContactMessage $message */
$message = $data['message'];
/** @var string|null $oeuvreTitre */
$oeuvreTitre = is_string($data['oeuvreTitre'] ?? null) ? $data['oeuvreTitre'] : null;

$mailto = 'mailto:' . $message->senderEmail
    . '?subject=' . rawurlencode('Re: ' . $message->subject);

$actionStatut = $base . '/admin/messages/' . $message->id . '/statut';
?>
<div class="admin-page admin-page--etroite">
    <p class="fil-retour"><a href="<?= attr($base . '/admin/messages') ?>">← Retour aux messages</a></p>

    <h1><?= e($message->subject) ?></h1>

    <dl class="message-entete">
        <dt>Expéditeur</dt>
        <dd><?= e($message->senderName) ?> &lt;<?= e($message->senderEmail) ?>&gt;</dd>
        <dt>Reçu le</dt>
        <dd><?= e($message->createdAt?->format('d/m/Y à H:i') ?? '') ?></dd>
        <?php if ($oeuvreTitre !== null) : ?>
        <dt>À propos de l’œuvre</dt>
        <dd><?= e($oeuvreTitre) ?></dd>
        <?php endif; ?>
    </dl>

    <div class="message-corps" style="white-space:pre-wrap;"><?= e($message->body) ?></div>

    <p class="actions">
        <a class="bouton" href="<?= attr($mailto) ?>">Répondre par e-mail</a>
    </p>

    <div class="message-statuts">
        <form method="post" action="<?= attr($actionStatut) ?>">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <input type="hidden" name="statut" value="answered">
            <button type="submit" class="lien-bouton">Marquer répondu</button>
        </form>
        <form method="post" action="<?= attr($actionStatut) ?>">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <input type="hidden" name="statut" value="spam">
            <button type="submit" class="lien-bouton">Marquer indésirable</button>
        </form>
        <form method="post" action="<?= attr($base . '/admin/messages/' . $message->id . '/suppression') ?>"
              data-confirmation="Supprimer définitivement ce message ?">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
            <button type="submit" class="lien-bouton">Supprimer</button>
        </form>
    </div>
</div>
