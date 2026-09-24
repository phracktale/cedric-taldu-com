<?php

declare(strict_types=1);

namespace App\Domain\Order;

/**
 * Identité du vendeur portée sur les factures (revue du 2026-09-24).
 *
 * Réglage `shop.seller` : { name, address (lignes), siret, email, extra }.
 * Rien d'écrit en dur : un autre artiste renseigne la sienne en back-office.
 * Sans réglage, le nom et l'e-mail de l'artiste (environnement) servent de repli.
 */
final class SellerIdentity
{
    public const SETTING = 'shop.seller';

    private function __construct(
        public readonly string $name,
        public readonly string $address,
        public readonly string $siret,
        public readonly string $email,
        public readonly string $extra,
    ) {
    }

    /**
     * @param array<string, mixed> $setting
     */
    public static function fromSetting(array $setting, string $fallbackName = '', string $fallbackEmail = ''): self
    {
        $name = self::clean($setting['name'] ?? null, 120);
        $email = self::clean($setting['email'] ?? null, 190);

        return new self(
            $name !== '' ? $name : self::clean($fallbackName, 120),
            self::clean($setting['address'] ?? null, 400, true),
            self::clean($setting['siret'] ?? null, 40),
            $email !== '' ? $email : self::clean($fallbackEmail, 190),
            self::clean($setting['extra'] ?? null, 200),
        );
    }

    /**
     * @return list<string>
     */
    public function addressLines(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->address)), static fn (string $l): bool => $l !== ''));
    }

    /** Une facture complète porte au moins le nom, l'adresse et le SIRET. */
    public function isComplete(): bool
    {
        return $this->name !== '' && $this->addressLines() !== [] && $this->siret !== '';
    }

    /**
     * @return array{name: string, address: string, siret: string, email: string, extra: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'siret' => $this->siret,
            'email' => $this->email,
            'extra' => $this->extra,
        ];
    }

    private static function clean(mixed $value, int $max, bool $multiline = false): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = str_replace("\r\n", "\n", $value);
        $value = (string) preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $value);

        return mb_substr(trim($value), 0, $max);
    }
}
