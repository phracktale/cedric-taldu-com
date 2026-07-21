<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Le stockage des sessions est inutilisable.
 *
 * Une session qui ne peut pas etre ecrite n'est pas une session degradee : c'est
 * l'absence de session. Sans cette exception, PHP se contente d'une alerte,
 * repart d'une session neuve a chaque requete, et le seul symptome visible est
 * un « 419 Formulaire expiré » a la connexion — un message qui envoie chercher
 * le probleme du cote du jeton CSRF, ou il n'est pas.
 *
 * Le chemin figure dans le message : il part au journal et vers une page 500
 * generique portant un identifiant de correlation, jamais vers le navigateur
 * (06-securite §10).
 */
final class SessionUnavailable extends RuntimeException
{
    public static function forPath(string $path, string $reason): self
    {
        return new self(sprintf(
            'Le stockage des sessions est inutilisable (%s) : %s',
            $path,
            $reason,
        ));
    }
}
