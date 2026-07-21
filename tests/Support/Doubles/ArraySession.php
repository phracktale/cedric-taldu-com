<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Core\SessionInterface;

/**
 * Session en memoire. Aucun test ne demarre de session PHP reelle : les tests
 * fonctionnels passent par le Kernel, pas par un serveur.
 */
final class ArraySession implements SessionInterface
{
    /** @var array<string, string> */
    private array $values = [];

    private string $id;

    /** Nombre de regenerations, pour verifier la parade a la fixation de session. */
    public int $regenerations = 0;

    public function __construct(string $id = 'session-initiale')
    {
        $this->id = $id;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function clear(): void
    {
        $this->values = [];
    }

    public function regenerateId(): void
    {
        $this->regenerations++;
        $this->id = 'session-' . $this->regenerations;
    }
}
