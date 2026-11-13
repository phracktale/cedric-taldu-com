<?php

declare(strict_types=1);

namespace APress\Core\Repository;

use APress\Core\Extension\ExtensionState;
use APress\Core\Extension\ExtensionStateStore;
use PDO;

final class PdoExtensionStateStore implements ExtensionStateStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT name, active, version, changed_at FROM core_extensions ORDER BY name ASC'
        );
        $states = [];

        foreach ($statement !== false ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            /** @var array{name: string, active: int|string, version: string, changed_at: string} $row */
            $states[$row['name']] = new ExtensionState(
                $row['name'],
                (int) $row['active'] === 1,
                $row['version'],
                $row['changed_at'],
            );
        }

        return $states;
    }

    public function save(ExtensionState $state): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO core_extensions (name, active, version, changed_at)
             VALUES (:name, :active, :version, :changed_at)
             ON DUPLICATE KEY UPDATE active = VALUES(active), version = VALUES(version),
                 changed_at = VALUES(changed_at)'
        );
        $statement->execute([
            'name' => $state->name,
            'active' => $state->active ? 1 : 0,
            'version' => $state->version,
            'changed_at' => $state->changedAt,
        ]);
    }
}
