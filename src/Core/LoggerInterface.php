<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Journal applicatif.
 *
 * 06-securite §10 : les evenements de securite y sont consignes — echecs de
 * connexion, rejets CSRF, signatures de webhook invalides, depassements de
 * limite, uploads refuses.
 */
interface LoggerInterface
{
    /**
     * @param array<string, string|int|float|bool|null> $context
     * @return string identifiant de correlation, affichable sur une page 500
     */
    public function log(LogLevel $level, string $message, array $context = []): string;
}
