<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Core\RandomInterface;

/**
 * Codes de secours a usage unique de la 2FA.
 *
 * 04-back-office §1. Un code de secours vaut le compte entier — il contourne le
 * second facteur — mais il doit rester recopiable a la main depuis une feuille
 * imprimee. D'ou trois choix :
 *
 *  - format « xxxxx-xxxxx », minuscules, dix caracteres utiles ;
 *  - normalisation tolerante a la casse, aux espaces et au tiret oublie ;
 *  - empreinte SHA-256 POIVREE plutot qu'Argon2id. Argon2id serait plus robuste
 *    contre la force brute hors ligne, mais il faudrait alors le calculer pour
 *    CHAQUE code jusqu'a trouver le bon — dix hachages a 130 ms sur un ecran de
 *    connexion. Le poivre, absent de la base, tient ce role : sans lui une fuite
 *    du seul dump ne donne rien, et avec le fichier .env l'attaquant a de toute
 *    facon deja tout.
 */
final class BackupCodes
{
    /** Nombre de codes remis a l'artiste lors de l'activation de la 2FA. */
    public const COUNT = 10;

    /** Octets tires par code : cinq octets, soit dix caracteres hexadecimaux. */
    private const BYTES = 5;

    public function __construct(private readonly string $pepper)
    {
    }

    /**
     * Codes EN CLAIR, rendus une seule fois.
     *
     * L'appelant les affiche puis n'en conserve que les empreintes : c'est le
     * seul moment de leur vie ou ils sont lisibles.
     *
     * @return list<string>
     */
    public function generate(RandomInterface $random): array
    {
        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = strtolower(substr($random->hex(self::BYTES), 0, 10));

            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return $codes;
    }

    public function hash(string $code): string
    {
        return hash('sha256', $this->pepper . "\0" . self::normalize($code));
    }

    /**
     * Forme canonique : minuscules, sans separateur.
     *
     * L'artiste recopie le code d'une feuille — majuscules, tiret oublie, espace
     * en trop. Aucune de ces variantes ne doit le mettre dehors.
     */
    public static function normalize(string $code): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $code));
    }
}
