<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Ce que la reclamation d'un evenement Stripe autorise.
 */
enum EventClaim
{
    /** A traiter : jamais vu, ou vu mais jamais mene a terme. */
    case Fresh;

    /** Deja traite : repondre 200 immediatement, sans aucun effet. */
    case AlreadyProcessed;

    /** Identifiant inexploitable : repondre 400, sans rien ecrire. */
    case Invalid;
}
