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

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
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

        $bits = '';

        foreach (str_split($normalized) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                return null;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        // Les bits de queue qui ne completent pas un octet sont du remplissage :
        // les interpreter ajouterait un octet nul au secret.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes === '' ? null : $bytes;
    }
}
