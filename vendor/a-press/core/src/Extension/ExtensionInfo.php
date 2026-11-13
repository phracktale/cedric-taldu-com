<?php

declare(strict_types=1);

namespace APress\Core\Extension;

/**
 * Une ligne de la page « Extensions » : ce que le disque et la base disent
 * d'une extension. `manifest` est nul quand le manifeste est illisible ou
 * quand le dossier a disparu alors que la base garde un état ; `error`
 * explique alors pourquoi, et aussi quand l'extension est incompatible.
 */
final class ExtensionInfo
{
    public readonly bool $active;

    public function __construct(
        public readonly string $name,
        public readonly ?Manifest $manifest,
        public readonly ?ExtensionState $state,
        public readonly ?string $error,
    ) {
        $this->active = $state !== null && $state->active;
    }
}
