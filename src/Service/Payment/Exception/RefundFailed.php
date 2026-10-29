<?php

declare(strict_types=1);

namespace App\Service\Payment\Exception;

use RuntimeException;

/**
 * Stripe a refusé un remboursement (2026-10-29) : rien n'a été rendu, donc
 * rien ne change côté site. Le message sert au journal, jamais à l'affichage.
 */
final class RefundFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('Remboursement refusé par Stripe : ' . $reason . '.');
    }
}
