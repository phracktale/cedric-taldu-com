<?php

declare(strict_types=1);

namespace APress\Core\Extension;

/**
 * État enregistré d'une extension : active ou non, et la version dont les
 * migrations ont été appliquées. Une extension désactivée garde sa ligne :
 * ses données restent en base et la réactivation ne rejoue rien.
 */
final class ExtensionState
{
    public function __construct(
        public readonly string $name,
        public readonly bool $active,
        public readonly string $version,
        public readonly string $changedAt,
    ) {
    }
}
