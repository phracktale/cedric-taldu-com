<?php

declare(strict_types=1);

namespace APress\Core\Extension;

interface ExtensionStateStore
{
    /**
     * @return array<string, ExtensionState> par nom d'extension
     */
    public function all(): array;

    public function save(ExtensionState $state): void;
}
