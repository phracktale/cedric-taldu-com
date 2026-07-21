<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

final class InvalidDimensions extends DomainException
{
    public static function forMillimeters(int $widthMm, int $heightMm): self
    {
        return new self(sprintf(
            'Des dimensions d\'œuvre doivent être strictement positives (%d × %d mm reçus).',
            $widthMm,
            $heightMm
        ));
    }
}
