<?php

declare(strict_types=1);

namespace App\Service\Spam;

/**
 * Décision de limitation de débit.
 *
 * Extraite pour que les gardes qui s'appuient sur elle — {@see SpamGuard} —
 * restent testables sans base : un double en mémoire suffit, là où le
 * {@see RateLimiter} réel exige la table `rate_limits`.
 */
interface Throttle
{
    /**
     * Enregistre une tentative et dit si elle reste dans les clous.
     *
     * @param string $scope      portée, par exemple « contact.submit »
     * @param string $identifier adresse IP, jamais stockée telle quelle
     * @param int    $limit      nombre de tentatives tolérées dans la fenêtre
     * @param int    $window     largeur de la fenêtre, en secondes
     */
    public function allow(string $scope, string $identifier, int $limit, int $window): bool;
}
