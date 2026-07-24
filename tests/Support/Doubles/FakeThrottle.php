<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Service\Spam\Throttle;

/**
 * Limitation de débit en mémoire, pour les tests du {@see \App\Service\Spam\SpamGuard}.
 *
 * Compte les appels par portée et refuse au-delà de la limite demandée, sans
 * base ni horloge : il éprouve la logique du garde, pas celle du compteur réel
 * (couvert par les tests d'intégration du {@see \App\Service\Spam\RateLimiter}).
 */
final class FakeThrottle implements Throttle
{
    /** @var array<string, int> */
    private array $hits = [];

    public function allow(string $scope, string $identifier, int $limit, int $window): bool
    {
        $key = $scope . "\0" . $identifier;
        $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;

        return $this->hits[$key] <= $limit;
    }
}
