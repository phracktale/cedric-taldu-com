<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Source unique de l'heure courante.
 *
 * ARCHITECTURE §4 : l'heure vient toujours d'ici, jamais d'un `new DateTime()`
 * direct. C'est ce qui rend testables les reservations qui expirent, les sessions
 * inactives, les horodatages signes de formulaire et les purges de conservation.
 */
interface ClockInterface
{
    /**
     * Instant courant, toujours en UTC.
     */
    public function now(): DateTimeImmutable;
}
