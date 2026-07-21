<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * Une langue non servie par le site a ete demandee.
 */
final class UnsupportedLocale extends DomainException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf(
            'La langue « %s » n\'est pas servie par le site.',
            preg_replace('/[^\x20-\x7E]/', '?', $code) ?? '?'
        ));
    }
}
