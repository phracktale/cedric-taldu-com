<?php

declare(strict_types=1);

namespace App\Service\Spam;

use App\Domain\Locale;

/**
 * Les signaux bruts d'une soumission de formulaire, tels que le contrôleur les
 * extrait de la requête, avant tout jugement.
 *
 * Value object figé : le {@see SpamGuard} ne lit jamais la requête directement,
 * il reçoit ces signaux. C'est ce qui le rend testable sans HTTP.
 */
final class SpamSignals
{
    public function __construct(
        /** Valeur du champ-piège : vide chez un humain, remplie par un robot. */
        public readonly string $honeypot,
        /** Jeton d'horodatage signé émis à l'affichage du formulaire. */
        public readonly string $timestamp,
        /** Adresse IP du client, pour la limitation de débit (jamais stockée en clair). */
        public readonly string $clientIp,
        /** Corps du message, soumis aux heuristiques. */
        public readonly string $message,
        /** Langue du formulaire, pour l'heuristique d'accents. */
        public readonly Locale $locale,
    ) {
    }
}
