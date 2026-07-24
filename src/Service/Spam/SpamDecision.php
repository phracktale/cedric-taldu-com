<?php

declare(strict_types=1);

namespace App\Service\Spam;

/**
 * Issue d'un contrôle anti-spam (06-securite §6).
 */
enum SpamDecision
{
    /** Message légitime : à enregistrer et à notifier. */
    case Accept;

    /**
     * Rejet SILENCIEUX : honeypot, horodatage ou débit. On répond comme si tout
     * allait bien, mais rien n'est enregistré ni notifié — informer le robot de
     * son échec l'aiderait à contourner le garde.
     */
    case Reject;

    /**
     * Signalé par les heuristiques : enregistré avec le statut `spam`, sans
     * notification. Conservé pour qu'un faux positif reste consultable.
     */
    case Flag;
}
