<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Resultat d'un enregistrement de media.
 *
 * `wasDuplicate` n'est pas un detail d'implementation : l'artiste qui reverse la
 * meme image doit savoir qu'elle etait deja la, sinon il croit avoir echoue et
 * recommence. Le compte de la mediatheque, lui, n'augmente pas.
 */
final class StoredMedia
{
    public function __construct(
        public readonly int $id,
        public readonly bool $wasDuplicate,
    ) {
    }
}
