<?php

declare(strict_types=1);

namespace App\Domain\Contact;

/**
 * Cycle de vie d'un message de contact (01-modele §6, 04-back-office §10).
 *
 * `Spam` est attribué à l'enregistrement par le {@see \App\Service\Spam\SpamGuard}
 * quand le score dépasse le seuil ; les autres transitions sont manuelles depuis
 * la boîte de réception. Aucun message n'est supprimé automatiquement, hormis la
 * purge RGPD des indésirables à 90 jours.
 */
enum MessageStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Answered = 'answered';
    case Spam = 'spam';
}
