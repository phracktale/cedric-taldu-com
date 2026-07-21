<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Base32 selon la RFC 4648, alphabet standard.
 *
 * Ecrit plutot qu'installe (CLAUDE.md §5). C'est l'encodage qu'attendent les
 * applications d'authentification : un secret TOTP se saisit a la main ou se lit
 * en QR code, et base32 n'emploie ni minuscule ni caractere ambigu.
 *
 * Le remplissage par « = » est produit a l'encodage — la RFC l'impose — et
 * tolere a la lecture, comme les minuscules et les espaces : un secret recopie
 * a la main arrive rarement propre.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Le transcodage passe par un ACCUMULATEUR ENTIER et non par une chaine de
     * « 0 » et de « 1 ». La version textuelle se lit plus vite, mais elle oblige
     * a repasser par bindec(), dont le type de retour est int|float : sur une
     * entree assez longue, la valeur deborde silencieusement en flottant et le
     * caractere produit devient faux. Ici, tout reste entier par construction.
     */
    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $buffer = 0;
        $bits = 0;
        $encoded = '';

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 0x1F];
            }
        }

        // Bits de queue : completes par des zeros a droite, comme l'impose la RFC.
        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }

        // Un bloc base32 fait huit caracteres : on complete jusqu'au multiple.
        $remainder = strlen($encoded) % 8;

        return $remainder === 0 ? $encoded : $encoded . str_repeat('=', 8 - $remainder);
    }

    /**
     * @return string|null null si l'entree n'est pas du base32 valide
     */
    public static function decode(string $encoded): ?string
    {
        $normalized = strtoupper(str_replace([' ', '-', '='], '', $encoded));

        if ($normalized === '') {
            return null;
        }

        $buffer = 0;
        $bits = 0;
        $bytes = '';

        foreach (str_split($normalized) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                return null;
            }

            $buffer = ($buffer << 5) | $index;
            $bits += 5;

            // Les bits de queue qui ne completent pas un octet sont du
            // remplissage : les interpreter ajouterait un octet nul au secret.
            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $bytes === '' ? null : $bytes;
    }
}
