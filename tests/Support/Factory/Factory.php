<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use PDO;

/**
 * Base des factories de test.
 *
 * tests/CLAUDE.md : « Donnees par factories, pas par insertion SQL a la main ».
 * Un test qui insere lui-meme ses lignes decrit la base au lieu de decrire la
 * regle metier, et casse au premier changement de schema.
 *
 * Les identifiants sont attribues par la base, jamais fixes par le test : deux
 * factories appelees a la suite ne peuvent donc pas se marcher dessus.
 */
abstract class Factory
{
    protected static int $sequence = 0;

    public function __construct(protected readonly PDO $pdo)
    {
    }

    /**
     * Compteur croissant, pour les valeurs qui doivent rester uniques —
     * references d'atelier, sommes de controle, noms de fichiers.
     */
    protected static function next(): int
    {
        return ++self::$sequence;
    }

    protected function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, string|int|null> $values
     */
    protected function insert(string $sql, array $values): void
    {
        $this->pdo->prepare($sql)->execute($values);
    }
}
