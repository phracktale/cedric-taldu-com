<?php

declare(strict_types=1);

namespace App\Service\Analytics;

/**
 * Mesure d'audience par Matomo auto-hébergé (revue du 2026-09-24).
 *
 * Activée seulement quand MATOMO_URL (https) et MATOMO_SITE_ID (numérique) sont
 * fournis. L'adresse finit dans la CSP et dans un attribut HTML : elle est
 * validée strictement, toute forme douteuse désactive la mesure.
 */
final class MatomoConfig
{
    private function __construct(
        public readonly string $url,
        public readonly string $siteId,
    ) {
    }

    public static function fromEnv(?string $url, ?string $siteId): ?self
    {
        $url = trim((string) $url);
        $siteId = trim((string) $siteId);

        if ($siteId === '' || !ctype_digit($siteId)) {
            return null;
        }

        if (preg_match('#^https://[a-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~/-]*)?$#i', $url) !== 1) {
            return null;
        }

        return new self(rtrim($url, '/') . '/', $siteId);
    }

    /** Origine seule (schéma, hôte, port), pour la CSP. */
    public function origin(): string
    {
        $parts = parse_url($this->url);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return 'https://' . ($parts['host'] ?? '') . $port;
    }
}
