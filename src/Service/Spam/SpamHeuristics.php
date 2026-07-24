<?php

declare(strict_types=1);

namespace App\Service\Spam;

use App\Domain\Locale;

/**
 * Heuristiques contribuant à `spam_score` (06-securite §6.4).
 *
 * Chaque signal ajoute quelques points ; aucun ne rejette seul. C'est le
 * {@see SpamGuard} qui, au-delà d'un seuil, classe le message en `spam` et
 * n'en notifie pas l'artiste — sans jamais le supprimer, pour qu'un faux
 * positif reste consultable dans la boîte.
 *
 * Volontairement conservateur : mieux vaut laisser passer un indésirable que
 * classer à tort un vrai client. Les signaux sûrs (honeypot, horodatage, débit)
 * vivent ailleurs et, eux, rejettent.
 */
final class SpamHeuristics
{
    /** Au-delà de deux liens, un message de contact devient suspect. */
    private const URL_LIMIT = 2;

    /** En deçà, trop court pour conclure de l'absence d'accents ou de bas-de-casse. */
    private const MIN_LETTERS = 40;

    /** Nombre de capitales à partir duquel « tout en majuscules » a un sens. */
    private const MIN_UPPERCASE = 8;

    public function score(string $message, Locale $locale): int
    {
        $score = 0;

        if ($this->urlCount($message) > self::URL_LIMIT) {
            $score += 2;
        }

        if ($this->isShouting($message)) {
            $score += 1;
        }

        if ($this->lacksExpectedAccents($message, $locale)) {
            $score += 1;
        }

        return $score;
    }

    private function urlCount(string $message): int
    {
        return preg_match_all('#https?://#i', $message)
            + preg_match_all('#\bwww\.#i', $message);
    }

    /**
     * Message entièrement capitalisé : aucune minuscule, et assez de capitales
     * pour que ce ne soit pas un simple sigle.
     */
    private function isShouting(string $message): bool
    {
        $lowercase = preg_match('/\p{Ll}/u', $message);
        $uppercase = preg_match_all('/\p{Lu}/u', $message);

        return $lowercase === 0 && $uppercase >= self::MIN_UPPERCASE;
    }

    /**
     * Un texte français d'une certaine longueur sans le moindre accent est
     * suspect : les robots composent en ASCII. Neutre hors du français.
     */
    private function lacksExpectedAccents(string $message, Locale $locale): bool
    {
        if ($locale !== Locale::Fr) {
            return false;
        }

        if (preg_match_all('/\p{L}/u', $message) < self::MIN_LETTERS) {
            return false;
        }

        return preg_match('/[àâäçéèêëîïôöùûüÿœæ]/iu', $message) === 0;
    }
}
