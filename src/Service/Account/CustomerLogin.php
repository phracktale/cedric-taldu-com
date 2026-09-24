<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Core\ClockInterface;
use App\Repository\CustomerLoginRepository;
use App\Repository\NewsletterRepository;

/**
 * Connexion à l'espace client par lien à usage unique (revue du 2026-09-24).
 *
 * Jeton de 32 octets aléatoires (hexadécimal), valable 20 minutes, dont seule
 * l'empreinte SHA-256 est stockée. Le jeton mal formé est écarté avant toute
 * requête.
 */
final class CustomerLogin
{
    private const LIFETIME = '+20 minutes';

    public function __construct(
        private readonly CustomerLoginRepository $tokens,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return string jeton en clair, à placer dans le lien envoyé
     */
    public function issue(string $email): string
    {
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();

        $this->tokens->store(NewsletterRepository::normalize($email), hash('sha256', $token), $now->modify(self::LIFETIME), $now);

        return $token;
    }

    /**
     * @return string|null adresse ouverte par le jeton, s'il est valide
     */
    public function consume(string $token): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return null;
        }

        return $this->tokens->consume(hash('sha256', $token), $this->clock->now());
    }
}
