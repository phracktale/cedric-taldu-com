<?php

declare(strict_types=1);

namespace APress\Core\Extension;

use RuntimeException;

final class InvalidManifest extends RuntimeException
{
    public static function because(string $path, string $reason): self
    {
        return new self(sprintf('Manifeste invalide (%s) : %s', $path, $reason));
    }
}
