<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Source d'alea cryptographique.
 *
 * Derriere une interface pour que les tests puissent forger des jetons exacts
 * (07-tests-tdd §3, double SequenceRandom) sans jamais affaiblir le generateur
 * reel.
 */
interface RandomInterface
{
    /**
     * @param int $bytes nombre d'octets ; la chaine rendue fait le double en hexadecimal
     */
    public function hex(int $bytes): string;
}
