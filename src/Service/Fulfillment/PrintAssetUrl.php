<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

/**
 * Jeton signé donnant accès, en lecture, au fichier d'impression d'une œuvre.
 *
 * Prodigi télécharge l'image prête-à-imprimer par une URL publique. Le fichier
 * vit hors webroot ; on l'expose donc par une route dédiée, protégée par un
 * jeton porteur de l'identifiant de l'œuvre et d'une signature HMAC. Sans le
 * secret, l'URL n'est pas devinable, et toute altération invalide le jeton.
 *
 * Le secret est le poivre applicatif, avec un préfixe de domaine propre, pour
 * qu'un jeton d'impression ne vaille jamais dans un autre usage du poivre.
 */
final class PrintAssetUrl
{
    private const DOMAIN = 'prodigi-print:';

    public function __construct(private readonly string $secret)
    {
    }

    public function token(int $artworkId): string
    {
        return $artworkId . '.' . $this->sign($artworkId);
    }

    public function verify(string $token): ?int
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return null;
        }

        $artworkId = (int) $parts[0];

        if ($artworkId <= 0) {
            return null;
        }

        return hash_equals($this->sign($artworkId), $parts[1]) ? $artworkId : null;
    }

    private function sign(int $artworkId): string
    {
        return substr(hash_hmac('sha256', self::DOMAIN . $artworkId, $this->secret), 0, 32);
    }
}
