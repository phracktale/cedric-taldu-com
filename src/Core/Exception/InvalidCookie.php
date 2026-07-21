<?php

declare(strict_types=1);

namespace App\Core\Exception;

use LogicException;

/**
 * Un cookie a ete construit avec un nom inexploitable ou sans le prefixe de
 * l'application.
 */
final class InvalidCookie extends LogicException
{
    public static function forName(string $name, string $expectation): self
    {
        return new self(sprintf(
            'Nom de cookie « %s » refusé : %s',
            preg_replace('/[^\x20-\x7E]/', '?', $name) ?? '?',
            $expectation
        ));
    }
}
