<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Exception\InvalidOrderReference;
use Stringable;

/**
 * Reference de commande : « CT-2026-0001 » (01-modele §5).
 *
 * Elle est montree au client, reprise dans les e-mails, et sert de
 * client_reference_id cote Stripe. Elle arrive aussi par l'URL de consultation
 * de commande : sa validation est donc une frontiere de securite, pas une
 * commodite. Une valeur mal formee n'atteint jamais une requete.
 */
final class OrderReference implements Stringable
{
    private const PREFIX = 'CT';

    /** Rembourrage minimal ; la 10 000e commande s'ecrit sur cinq chiffres. */
    private const PAD = 4;

    private const PATTERN = '/^CT-(\d{4})-(\d{4,})$/';

    private function __construct(
        public readonly string $value,
        public readonly int $year,
        public readonly int $sequence,
    ) {
    }

    public static function fromString(string $raw): self
    {
        // preg_match s'arrete au premier octet nul et ignore ce qui suit un
        // saut de ligne avec le modificateur /D absent : les ancres $ de PHP
        // tolerent un \n final. On rejette donc explicitement tout caractere de
        // controle avant meme de filtrer la forme.
        if ($raw !== trim($raw) || strpbrk($raw, "\0\r\n\t") !== false) {
            throw InvalidOrderReference::malformed($raw);
        }

        if (preg_match(self::PATTERN, $raw, $matches) !== 1) {
            throw InvalidOrderReference::malformed($raw);
        }

        $sequence = (int) $matches[2];

        if ($sequence < 1) {
            throw InvalidOrderReference::malformed($raw);
        }

        return new self($raw, (int) $matches[1], $sequence);
    }

    /**
     * Reference qui suit la derniere connue pour cette annee.
     *
     * Le compteur repart a un au changement d'annee : sans cela, la reference
     * cesserait d'identifier l'exercice comptable de la commande.
     */
    public static function following(?self $last, int $year): self
    {
        $sequence = ($last !== null && $last->year === $year) ? $last->sequence + 1 : 1;

        return new self(self::format($year, $sequence), $year, $sequence);
    }

    private static function format(int $year, int $sequence): string
    {
        return sprintf(
            '%s-%04d-%s',
            self::PREFIX,
            $year,
            str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT),
        );
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
