<?php

declare(strict_types=1);

namespace APress\Core\Extension;

/**
 * Entrée du menu d'administration déclarée par une extension.
 *
 * La cible est un nom de route, jamais une URL : le site la résout avec son
 * générateur d'URL, ce qui garde le préfixe de chemin juste (/saga, /cedric-taldu).
 */
final class AdminMenuItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $routeName,
        public readonly string $group = 'extensions',
        public readonly int $position = 100,
    ) {
    }
}
