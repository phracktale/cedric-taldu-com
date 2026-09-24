<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Domain\Order\Address;
use App\Domain\Shipping\GeoPoint;
use Closure;
use InvalidArgumentException;
use JsonException;

/**
 * Géocodage par la Base Adresse Nationale (api-adresse.data.gouv.fr).
 *
 * Service public et gratuit, sans clé, appelé CÔTÉ SERVEUR : aucune origine
 * tierce n'entre dans la CSP. Il ne couvre que la France ; une adresse étrangère
 * n'est pas interrogée. Une correspondance incertaine (score < 0,5) est écartée
 * plutôt que d'accorder une remise en main propre sur une adresse mal reconnue.
 */
final class BanGeocoder implements Geocoder
{
    private const ENDPOINT = 'https://api-adresse.data.gouv.fr/search/?';
    private const MIN_SCORE = 0.5;
    private const TIMEOUT_SECONDS = 4;

    /** @var Closure(string): ?string */
    private readonly Closure $fetch;

    /**
     * @param (callable(string): ?string)|null $fetch lecture HTTP GET ; injectée en test
     */
    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch === null ? self::httpGet(...) : Closure::fromCallable($fetch);
    }

    public function locate(Address $address): ?GeoPoint
    {
        if (strtoupper($address->country) !== 'FR') {
            return null;
        }

        $url = self::ENDPOINT . http_build_query([
            'q' => trim($address->line1 . ' ' . $address->city),
            'postcode' => $address->postalCode,
            'limit' => 1,
        ]);

        $body = ($this->fetch)($url);

        return $body === null ? null : self::parse($body);
    }

    private static function parse(string $body): ?GeoPoint
    {
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $feature = is_array($data) && is_array($data['features'][0] ?? null) ? $data['features'][0] : [];
        $coordinates = $feature['geometry']['coordinates'] ?? null;
        $score = $feature['properties']['score'] ?? 0;

        if (!is_array($coordinates) || !is_numeric($coordinates[0] ?? null) || !is_numeric($coordinates[1] ?? null)) {
            return null;
        }

        if (!is_numeric($score) || (float) $score < self::MIN_SCORE) {
            return null;
        }

        try {
            // GeoJSON : [longitude, latitude].
            return new GeoPoint((float) $coordinates[1], (float) $coordinates[0]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function httpGet(string $url): ?string
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::TIMEOUT_SECONDS,
            'header' => "Accept: application/json\r\n",
        ]]);

        // Service injoignable : un avertissement PHP, pas une exception. On le
        // capte le temps de l'appel ; l'appelant reçoit null et refuse proprement.
        set_error_handler(static fn (): bool => true);

        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        return $body === false ? null : $body;
    }
}
