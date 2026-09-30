<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * Une requete modifiante est arrivee sans jeton CSRF valide.
 *
 * Statut 403 (419 jusqu'au 2026-09-30) : 419 n'est pas normalisé, et Apache le
 * réécrivait en « 500 Internal Server Error ». La page d'erreur reconnaît
 * l'exception et propose de recharger le formulaire plutôt que d'afficher
 * « accès interdit » (ErrorResponder).
 */
final class CsrfTokenMismatch extends HttpException
{
    public function statusCode(): int
    {
        return 403;
    }
}
