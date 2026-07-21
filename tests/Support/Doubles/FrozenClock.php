<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Core\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Horloge gelee, employee par TOUS les tests.
 *
 * tests/CLAUDE.md : « un test qui depend de time() reel est un test instable,
 * donc un defaut ». Les expirations se testent en avancant explicitement
 * l'horloge, jamais en attendant.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $instant = '2026-07-21 09:30:00')
    {
        $this->now = new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
