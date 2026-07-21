<?php

declare(strict_types=1);

namespace App\Core\Exception;

use LogicException;

/**
 * Une redirection a ete demandee vers une destination qui n'est pas un chemin
 * interne du site.
 *
 * Une redirection ouverte permet de faire pointer un lien portant notre domaine
 * vers une page de hameconnage : elle est refusee, jamais assainie en silence.
 */
final class UnsafeRedirect extends LogicException
{
    public static function forLocation(string $location): self
    {
        return new self(sprintf(
            'Redirection refusée vers « %s » : seul un chemin interne commençant par « / » est autorisé.',
            preg_replace('/[^\x20-\x7E]/', '?', $location) ?? '?'
        ));
    }

    public static function forStatus(int $status): self
    {
        return new self(sprintf('Le statut %d n\'est pas un statut de redirection.', $status));
    }
}
