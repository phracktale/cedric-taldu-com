<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use InvalidArgumentException;

/**
 * Transporteurs branchés sur la boutique, le premier étant celui par défaut.
 *
 * Une commande enregistre le NOM du transporteur (texte libre historique) : le
 * registre le retrouve pour construire le lien de suivi. Un nom inconnu (saisi à
 * la main, ancien transporteur) ne donne simplement pas de lien.
 */
final class CarrierRegistry
{
    /**
     * @param list<Carrier> $carriers
     */
    public function __construct(private readonly array $carriers)
    {
        if ($carriers === []) {
            throw new InvalidArgumentException('Au moins un transporteur est requis.');
        }
    }

    /**
     * Registre dont le transporteur choisi (par son code) passe en tête et
     * devient le transporteur par défaut ; un code inconnu ne change rien.
     *
     * @param list<Carrier> $carriers
     */
    public static function preferring(array $carriers, mixed $code): self
    {
        usort($carriers, static fn (Carrier $a, Carrier $b): int => (int) ($b->code() === $code) <=> (int) ($a->code() === $code));

        return new self($carriers);
    }

    public function default(): Carrier
    {
        return $this->carriers[0];
    }

    public function byName(?string $name): ?Carrier
    {
        $name = mb_strtolower(trim((string) $name));

        foreach ($this->carriers as $carrier) {
            if (mb_strtolower($carrier->name()) === $name || $carrier->code() === $name) {
                return $carrier;
            }
        }

        return null;
    }

    /**
     * @return list<Carrier>
     */
    public function all(): array
    {
        return $this->carriers;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (Carrier $carrier): string => $carrier->name(), $this->carriers);
    }
}
