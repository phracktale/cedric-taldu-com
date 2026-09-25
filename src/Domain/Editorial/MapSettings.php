<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

/**
 * Carte interactive (retours du 2026-09-25, Modules › Carte interactive) :
 * point central, zoom et marqueurs (titre, description, position). Réglage
 * `map`, rendu par le bloc « Carte » (Leaflet + tuiles OpenStreetMap, chargés
 * seulement au clic du visiteur).
 *
 * @phpstan-type Marker array{title: string, description: string, lat: float, lng: float}
 */
final class MapSettings
{
    public const SETTING = 'map';

    /** Amiens, à défaut de réglage. */
    private const DEFAULT_LAT = 49.8941;
    private const DEFAULT_LNG = 2.2958;
    private const DEFAULT_ZOOM = 12;

    public const MAX_MARKERS = 50;

    /**
     * @param list<Marker> $markers
     */
    private function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly int $zoom,
        public readonly array $markers,
    ) {
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $marqueurs = [];
        foreach (is_array($stored['markers'] ?? null) ? $stored['markers'] : [] as $m) {
            if (count($marqueurs) >= self::MAX_MARKERS || !is_array($m)) {
                continue;
            }
            $titre = is_string($m['title'] ?? null) ? trim($m['title']) : '';
            $lat = self::coordinate($m['lat'] ?? null, 90);
            $lng = self::coordinate($m['lng'] ?? null, 180);
            if ($titre !== '' && $lat !== null && $lng !== null) {
                $marqueurs[] = [
                    'title' => mb_substr($titre, 0, 120),
                    'description' => is_string($m['description'] ?? null) ? mb_substr(trim($m['description']), 0, 500) : '',
                    'lat' => $lat,
                    'lng' => $lng,
                ];
            }
        }

        $zoom = $stored['zoom'] ?? null;

        return new self(
            self::coordinate($stored['lat'] ?? null, 90) ?? self::DEFAULT_LAT,
            self::coordinate($stored['lng'] ?? null, 180) ?? self::DEFAULT_LNG,
            is_int($zoom) && $zoom >= 1 && $zoom <= 18 ? $zoom : self::DEFAULT_ZOOM,
            $marqueurs,
        );
    }

    /**
     * Saisie du back-office : `lat`, `lng`, `zoom`, puis `m{n}_titre`,
     * `m{n}_description`, `m{n}_lat`, `m{n}_lng` ; une ligne vide est ignorée.
     *
     * @param array<string, string|null> $input
     * @return array{0: self, 1: list<string>}
     */
    public static function fromForm(array $input): array
    {
        $erreurs = [];
        $valeur = static fn (string $cle): string => trim((string) ($input[$cle] ?? ''));

        $lat = self::read($valeur('lat'), 90, 'Point central', 'latitude', $erreurs);
        $lng = self::read($valeur('lng'), 180, 'Point central', 'longitude', $erreurs);
        $zoom = $valeur('zoom');
        if (preg_match('/^[0-9]{1,2}$/D', $zoom) !== 1 || (int) $zoom < 1 || (int) $zoom > 18) {
            $erreurs[] = 'Zoom : un nombre de 1 à 18.';
        }

        $marqueurs = [];
        for ($n = 0; $n < self::MAX_MARKERS && array_key_exists('m' . $n . '_titre', $input); $n++) {
            $titre = mb_substr($valeur('m' . $n . '_titre'), 0, 120);
            $description = mb_substr($valeur('m' . $n . '_description'), 0, 500);
            $mLat = $valeur('m' . $n . '_lat');
            $mLng = $valeur('m' . $n . '_lng');

            if ($titre === '' && $description === '' && $mLat === '' && $mLng === '') {
                continue;
            }

            $nom = $titre === '' ? 'Marqueur ' . ($n + 1) : 'Marqueur « ' . $titre . ' »';
            if ($titre === '') {
                $erreurs[] = $nom . ' : donnez-lui un titre.';
            }
            $la = self::read($mLat, 90, $nom, 'latitude', $erreurs);
            $lo = self::read($mLng, 180, $nom, 'longitude', $erreurs);

            if ($titre !== '' && $la !== null && $lo !== null) {
                $marqueurs[] = ['title' => $titre, 'description' => $description, 'lat' => $la, 'lng' => $lo];
            }
        }

        return [
            new self($lat ?? self::DEFAULT_LAT, $lng ?? self::DEFAULT_LNG, $erreurs === [] ? (int) $zoom : self::DEFAULT_ZOOM, $marqueurs),
            $erreurs,
        ];
    }

    /**
     * @return array{lat: float, lng: float, zoom: int, markers: list<Marker>}
     */
    public function toArray(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude, 'zoom' => $this->zoom, 'markers' => $this->markers];
    }

    /**
     * @param list<string> $erreurs
     */
    private static function read(string $raw, int $borne, string $objet, string $axe, array &$erreurs): ?float
    {
        $normalise = str_replace(',', '.', $raw);
        if (preg_match('/^-?[0-9]{1,3}(?:\.[0-9]{1,8})?$/D', $normalise) !== 1) {
            $erreurs[] = $objet . ' : ' . $axe . ' « ' . $raw . ' » illisible.';

            return null;
        }

        $nombre = (float) $normalise;
        if (abs($nombre) > $borne) {
            $erreurs[] = $objet . ' : ' . $axe . ' « ' . $raw . ' » hors de -' . $borne . ' à ' . $borne . '.';

            return null;
        }

        return $nombre;
    }

    private static function coordinate(mixed $value, int $borne): ?float
    {
        return (is_int($value) || is_float($value)) && abs((float) $value) <= $borne ? (float) $value : null;
    }
}
