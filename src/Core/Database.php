<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Fabrique de connexions PDO.
 *
 * Seul endroit du code ou un PDO est construit, et seul endroit ou ses options
 * sont decidees (src/CLAUDE.md). Les quatre options ne sont pas negociables :
 *
 *  - ERRMODE_EXCEPTION : sans elle, une requete en echec rend false et le code
 *    poursuit comme si de rien n'etait ;
 *  - EMULATE_PREPARES = false : avec l'emulation, PDO interpole lui-meme les
 *    parametres et l'echappement redevient une question de jeu de caracteres ;
 *  - FETCH_ASSOC et STRINGIFY_FETCHES = false : les entiers restent des entiers,
 *    ce qui compte pour des montants en centimes.
 *
 * Deux comptes distincts (06-securite §1) : l'applicatif, limite a
 * SELECT/INSERT/UPDATE/DELETE, et celui des migrations, employe au deploiement.
 */
final class Database
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $name,
        public readonly string $user,
        public readonly string $password,
    ) {
    }

    public static function fromEnv(Env $env, bool $migration = false, bool $useTestDatabase = false): self
    {
        return new self(
            host: $env->get('DB_HOST'),
            port: (int) $env->get('DB_PORT'),
            name: $useTestDatabase ? $env->get('DB_TEST_NAME') : $env->get('DB_NAME'),
            user: $env->get($migration ? 'DB_MIGRATION_USER' : 'DB_USER'),
            password: $env->get($migration ? 'DB_MIGRATION_PASSWORD' : 'DB_PASSWORD'),
        );
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->name,
        );
    }

    /**
     * @return array<int, mixed>
     */
    public static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    public function connect(): PDO
    {
        return new PDO($this->dsn(), $this->user, $this->password, self::options());
    }
}
