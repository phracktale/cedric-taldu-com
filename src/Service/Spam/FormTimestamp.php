<?php

declare(strict_types=1);

namespace App\Service\Spam;

use App\Core\ClockInterface;

/**
 * Horodatage signé d'un formulaire public (06-securite §6.2).
 *
 * Un champ caché porte l'instant de génération du formulaire, signé par HMAC.
 * À la soumission, on mesure le temps écoulé : moins de trois secondes trahit
 * un robot, plus de deux heures un formulaire éventé (le seuil est appliqué par
 * le SpamGuard, pas ici). La SIGNATURE interdit de forger un instant crédible —
 * sans elle, un robot poserait simplement une valeur vieille de dix secondes.
 *
 * Même dérivation de clé que {@see \App\Service\Content\PreviewToken} : la clé
 * vient du poivre de .env, jamais le poivre lui-même, pour qu'une fuite de jeton
 * n'apprenne rien sur les empreintes d'IP ni les codes de secours.
 */
final class FormTimestamp
{
    private readonly string $key;

    public function __construct(string $pepper, private readonly ClockInterface $clock)
    {
        $this->key = hash_hmac('sha256', 'form-timestamp', $pepper, true);
    }

    /**
     * Émet le jeton à poser dans un champ caché du formulaire.
     */
    public function issue(): string
    {
        $issuedAt = $this->clock->now()->getTimestamp();

        return dechex($issuedAt) . '-' . $this->sign($issuedAt);
    }

    /**
     * Nombre de secondes écoulées depuis l'émission, ou `null` si la signature
     * est invalide ou l'instant postérieur à maintenant (jeton manipulé).
     *
     * La comparaison de signature est en TEMPS CONSTANT (`hash_equals`) : un
     * `===` laisserait reconstituer la signature octet par octet.
     */
    public function elapsed(string $token): ?int
    {
        $parts = explode('-', $token, 2);

        if (count($parts) !== 2 || !ctype_xdigit($parts[0])) {
            return null;
        }

        $issuedAt = (int) hexdec($parts[0]);

        if (!hash_equals($this->sign($issuedAt), $parts[1])) {
            return null;
        }

        $elapsed = $this->clock->now()->getTimestamp() - $issuedAt;

        return $elapsed < 0 ? null : $elapsed;
    }

    private function sign(int $issuedAt): string
    {
        return hash_hmac('sha256', (string) $issuedAt, $this->key);
    }
}
