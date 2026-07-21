<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * Une requete modifiante est arrivee sans jeton CSRF valide.
 *
 * Le statut 419 n'est pas normalise, mais il distingue le jeton expire ou absent
 * d'un veritable refus d'acces : le front peut proposer de recharger le
 * formulaire plutot que d'afficher « acces interdit ».
 */
final class CsrfTokenMismatch extends HttpException
{
    public function statusCode(): int
    {
        return 419;
    }
}
