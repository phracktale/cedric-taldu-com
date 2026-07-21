<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\ServiceNotRegistered;

/**
 * Conteneur d'injection manuel, sans magie.
 *
 * Aucune auto-decouverte, aucune reflexion, aucun attribut : le cablage est ecrit
 * a la main dans config/services.php et se lit. Un service qui manque est un
 * oubli visible, pas un comportement a deviner.
 */
final class Container
{
    /** @var array<string, callable(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param callable(self): object $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, object $service): void
    {
        $this->instances[$id] = $service;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * Les services sont memorises : une meme requete partage une seule instance
     * de chaque service, y compris la connexion PDO.
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $factory = $this->factories[$id] ?? throw ServiceNotRegistered::forId($id);

        return $this->instances[$id] = $factory($this);
    }
}
