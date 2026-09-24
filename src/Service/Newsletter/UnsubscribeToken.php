<?php

declare(strict_types=1);

namespace App\Service\Newsletter;

use App\Repository\NewsletterRepository;

/**
 * Jeton de désinscription signé (revue du 2026-09-24).
 *
 * HMAC-SHA256 de l'adresse normalisée, clé dérivée du poivre applicatif : rien
 * à stocker, un lien valable pour chaque abonné (y compris dans l'export pour
 * l'outil d'envoi de l'artiste), et impossible à forger sans le poivre.
 */
final class UnsubscribeToken
{
    public function __construct(#[\SensitiveParameter] private readonly string $pepper)
    {
    }

    public function for(string $email): string
    {
        return hash_hmac('sha256', 'newsletter-unsubscribe:' . NewsletterRepository::normalize($email), $this->pepper);
    }

    public function verify(string $email, string $token): bool
    {
        return $token !== '' && hash_equals($this->for($email), $token);
    }
}
