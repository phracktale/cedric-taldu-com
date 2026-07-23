<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Exception\InvalidAddress;

/**
 * Adresse de livraison ou de facturation (01-modele §5, colonnes JSON).
 *
 * Les CR et LF sont refuses DES LA CONSTRUCTION, et non au moment de l'envoi
 * du courriel. L'adresse figure dans l'e-mail de commande ; interdire les
 * sauts de ligne au dernier moment suppose qu'on y pense au dernier moment, et
 * c'est exactement ce qu'on oublie (06-securite §6.6).
 */
final class Address
{
    private const MAX_LENGTH = 190;

    public readonly string $line1;
    public readonly ?string $line2;
    public readonly string $postalCode;
    public readonly string $city;
    public readonly string $country;

    public function __construct(
        string $line1,
        ?string $line2,
        string $postalCode,
        string $city,
        string $country,
    ) {
        $this->line1 = self::clean($line1, 'line1', required: true);
        $this->line2 = $line2 === null || trim($line2) === ''
            ? null
            : self::clean($line2, 'line2', required: false);
        $this->postalCode = self::clean($postalCode, 'postal_code', required: true);
        $this->city = self::clean($city, 'city', required: true);

        $normalised = strtoupper(self::clean($country, 'country', required: true));

        // Le pays choisit la zone d'expedition : « fr » enverrait la commande
        // en zone Monde et la surfacturerait de 26 €.
        if (preg_match('/^[A-Z]{2}$/', $normalised) !== 1) {
            throw InvalidAddress::countryCode($country);
        }

        $this->country = $normalised;
    }

    private static function clean(string $value, string $field, bool $required): string
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw InvalidAddress::controlCharacter($field);
        }

        $trimmed = trim($value);

        if ($required && $trimmed === '') {
            throw InvalidAddress::missing($field);
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidAddress::tooLong($field);
        }

        return $trimmed;
    }

    /**
     * @return array{line1: string, line2: string|null, postal_code: string, city: string, country: string}
     */
    public function toArray(): array
    {
        return [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }

    /**
     * Relecture d'une adresse figee en base.
     *
     * Une commande passee doit pouvoir etre relue meme si ses donnees ne
     * satisferaient plus les regles d'aujourd'hui : la lecture ne valide donc
     * pas, elle restitue.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $line1 = $row['line1'] ?? null;

        if (!is_string($line1)) {
            return null;
        }

        $string = static fn (mixed $v): string => is_string($v) ? $v : '';

        return new self(
            $line1,
            is_string($row['line2'] ?? null) ? (string) $row['line2'] : null,
            $string($row['postal_code'] ?? null),
            $string($row['city'] ?? null),
            $string($row['country'] ?? null),
        );
    }
}
