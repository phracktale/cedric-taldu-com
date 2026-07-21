<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/**
 * Verdict rendu sur une session d'administration.
 *
 * Trois facons de la fermer, trois cas distincts : le journal de securite doit
 * pouvoir dire « empreinte etrangere » et non « session expiree », sinon un vol
 * de cookie passe pour une inactivite et n'alerte personne (06-securite §10).
 *
 * Cote visiteur, en revanche, les trois se ressemblent : session detruite et
 * retour a la page de connexion. On ne dit jamais a un attaquant que son
 * empreinte a ete reconnue comme etrangere.
 */
enum SessionStatus: string
{
    case Valid = 'valid';

    /** Plus de trente minutes sans requete. */
    case IdleTimeout = 'idle_timeout';

    /** Ouverte depuis plus de douze heures, quelle qu'ait ete l'activite. */
    case AbsoluteTimeout = 'absolute_timeout';

    /** Navigateur ou reseau different de celui de l'ouverture. */
    case FingerprintMismatch = 'fingerprint_mismatch';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }

    /**
     * Motif journalise. Jamais affiche : le visiteur voit toujours le meme
     * message de session close.
     */
    public function reason(): string
    {
        return match ($this) {
            self::Valid => 'session valide',
            self::IdleTimeout => 'inactivité de plus de 30 minutes',
            self::AbsoluteTimeout => 'session ouverte depuis plus de 12 heures',
            self::FingerprintMismatch => 'empreinte de session étrangère',
        };
    }
}
