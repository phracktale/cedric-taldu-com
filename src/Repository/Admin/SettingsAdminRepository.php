<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use DateTimeImmutable;
use PDO;

/**
 * Écriture des réglages du site (table `settings`), côté back-office.
 *
 * La lecture publique passe par SettingRepository ; ici on ÉCRIT, en upsert sur
 * la clef (clef = PRIMARY KEY). La valeur est un document JSON — jamais du HTML
 * exécuté : ce qui doit être assaini l'est avant d'arriver ici.
 */
final class SettingsAdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<mixed> $value
     */
    public function save(string $key, array $value, DateTimeImmutable $now): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $statement = $this->pdo->prepare(
            'INSERT INTO settings (`key`, value, updated_at) VALUES (:key, :value, :now)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            'key' => $key,
            'value' => $json === false ? '[]' : $json,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
