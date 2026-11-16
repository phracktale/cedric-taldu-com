<?php

declare(strict_types=1);

namespace APress\Core\Content;

use RuntimeException;

/**
 * Publication refusée : une autre entrée publiée du même type a déjà cette
 * adresse. Le message s'affiche tel quel dans l'administration.
 */
final class SlugTaken extends RuntimeException
{
    public static function for(string $slug): self
    {
        return new self(sprintf('L’adresse « %s » est déjà utilisée par une autre entrée publiée de ce type.', $slug));
    }
}
