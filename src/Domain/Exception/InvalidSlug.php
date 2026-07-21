<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * Un slug n'a pas pu etre produit, ou la valeur fournie n'en est pas un.
 *
 * On refuse plutot que d'inventer : un titre entierement compose de caracteres
 * non translitterables — ideogrammes, emoji, ponctuation seule — n'a pas de
 * slug raisonnable, et le back-office demandera une saisie manuelle.
 */
final class InvalidSlug extends DomainException
{
    public static function forTitle(string $title): self
    {
        return new self(sprintf(
            'Aucun slug ne peut être produit à partir de « %s » : saisissez-le à la main.',
            preg_replace('/[^\x20-\x7E]/', '?', $title) ?? '?'
        ));
    }

    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'La valeur « %s » n\'est pas un slug valide (minuscules, chiffres, tirets simples).',
            preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '?'
        ));
    }

    public static function forSuffix(int $suffix): self
    {
        return new self(sprintf('Un suffixe de collision commence à 2, %d reçu.', $suffix));
    }
}
