<?php

declare(strict_types=1);

namespace App\Service\Spam;

/**
 * Garde anti-spam des formulaires publics de contact (06-securite §6).
 *
 * Enchaîne les contrôles du moins coûteux au plus coûteux, et du plus sûr au
 * plus heuristique :
 *
 *   1. honeypot rempli               → rejet silencieux ;
 *   2. horodatage absent, forgé,
 *      trop récent (< 3 s) ou éventé
 *      (> 2 h)                        → rejet silencieux ;
 *   3. débit dépassé (3/h, 10/j)     → rejet silencieux ;
 *   4. score d'heuristiques ≥ seuil  → signalé (`spam`, conservé, non notifié) ;
 *   5. sinon                         → accepté.
 *
 * Les trois premiers signaux sont SÛRS : ils rejettent. Le quatrième n'est
 * qu'un faisceau d'indices : il classe sans supprimer, pour ne jamais perdre
 * un vrai message pris à tort. Aucun CAPTCHA (06-securite §6.5) ; le point
 * d'extension éventuel se brancherait ici.
 */
final class SpamGuard
{
    /** En deçà, un humain n'a pas eu le temps de remplir : robot. */
    private const MIN_SECONDS = 3;

    /** Au-delà, le formulaire est éventé (rejeu d'une vieille page). */
    private const MAX_SECONDS = 7200;

    /** Limitation de débit par IP (06-securite §6.3). */
    private const HOUR_LIMIT = 3;
    private const HOUR_WINDOW = 3600;
    private const DAY_LIMIT = 10;
    private const DAY_WINDOW = 86400;

    /** Score d'heuristiques à partir duquel le message est classé indésirable. */
    private const SPAM_THRESHOLD = 3;

    public function __construct(
        private readonly FormTimestamp $timestamp,
        private readonly Throttle $throttle,
        private readonly SpamHeuristics $heuristics,
    ) {
    }

    public function evaluate(SpamSignals $signals): SpamVerdict
    {
        if ($signals->honeypot !== '') {
            return SpamVerdict::reject('honeypot');
        }

        $elapsed = $this->timestamp->elapsed($signals->timestamp);

        if ($elapsed === null || $elapsed < self::MIN_SECONDS || $elapsed > self::MAX_SECONDS) {
            return SpamVerdict::reject('timestamp');
        }

        // Les deux fenêtres sont éprouvées et incrémentées : une rafale sur
        // l'heure comme un ruissellement sur la journée doivent tomber.
        $withinHour = $this->throttle->allow('contact.hour', $signals->clientIp, self::HOUR_LIMIT, self::HOUR_WINDOW);
        $withinDay = $this->throttle->allow('contact.day', $signals->clientIp, self::DAY_LIMIT, self::DAY_WINDOW);

        if (!$withinHour || !$withinDay) {
            return SpamVerdict::reject('rate');
        }

        $score = $this->heuristics->score($signals->message, $signals->locale);

        if ($score >= self::SPAM_THRESHOLD) {
            return SpamVerdict::flag($score);
        }

        return SpamVerdict::accept($score);
    }
}
