<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

/**
 * État de la génération statique, affiché par la barre du back-office
 * (retours du 2026-09-25, point 7) : numéro d'incrément, date, nombre de
 * pages, durée totale, fraîcheur et journal page à page.
 *
 * Réglage `static.generation`. Les instants sont en UTC, au format ISO avec
 * millisecondes ; l'affichage les passe en heure de Paris.
 */
final class GenerationState
{
    public const SETTING = 'static.generation';

    /**
     * @param list<array{at: string, path: string, ms: int, status: int}> $log
     */
    public function __construct(
        public readonly int $number,
        public readonly ?string $at,
        public readonly int $count,
        public readonly int $totalMs,
        public readonly bool $stale,
        public readonly ?string $invalidatedAt,
        public readonly array $log,
    ) {
    }

    public static function none(): self
    {
        return new self(0, null, 0, 0, false, null, []);
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $log = [];
        foreach (is_array($stored['log'] ?? null) ? $stored['log'] : [] as $ligne) {
            if (!is_array($ligne)) {
                continue;
            }
            $log[] = [
                'at' => is_string($ligne['at'] ?? null) ? $ligne['at'] : '',
                'path' => is_string($ligne['path'] ?? null) ? $ligne['path'] : '',
                'ms' => is_int($ligne['ms'] ?? null) ? $ligne['ms'] : 0,
                'status' => is_int($ligne['status'] ?? null) ? $ligne['status'] : 0,
            ];
        }

        return new self(
            is_int($stored['number'] ?? null) ? $stored['number'] : 0,
            is_string($stored['at'] ?? null) ? $stored['at'] : null,
            is_int($stored['count'] ?? null) ? $stored['count'] : 0,
            is_int($stored['total_ms'] ?? null) ? $stored['total_ms'] : 0,
            ($stored['stale'] ?? false) === true,
            is_string($stored['invalidated_at'] ?? null) ? $stored['invalidated_at'] : null,
            $log,
        );
    }

    /**
     * @return array{number: int, at: string|null, count: int, total_ms: int, stale: bool, invalidated_at: string|null, log: list<array{at: string, path: string, ms: int, status: int}>}
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'at' => $this->at,
            'count' => $this->count,
            'total_ms' => $this->totalMs,
            'stale' => $this->stale,
            'invalidated_at' => $this->invalidatedAt,
            'log' => $this->log,
        ];
    }

    public function withStale(string $invalidatedAt): self
    {
        return new self($this->number, $this->at, $this->count, $this->totalMs, true, $invalidatedAt, $this->log);
    }
}
