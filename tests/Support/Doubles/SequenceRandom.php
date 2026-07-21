<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Core\RandomInterface;
use RuntimeException;

/**
 * Generateur previsible : rend les valeurs fournies, dans l'ordre.
 *
 * Permet d'ecrire des assertions exactes sur un jeton, un nom de fichier
 * televerse ou un jeton d'acces a une commande (07-tests-tdd §3).
 */
final class SequenceRandom implements RandomInterface
{
    /** @var list<string> */
    private array $remaining;

    /**
     * @param list<string> $sequence
     */
    public function __construct(array $sequence)
    {
        $this->remaining = $sequence;
    }

    public function hex(int $bytes): string
    {
        $value = array_shift($this->remaining);

        if ($value === null) {
            throw new RuntimeException('La séquence aléatoire de test est épuisée.');
        }

        return $value;
    }
}
