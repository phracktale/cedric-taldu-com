<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Liens « mot de passe oublié » de l'administration (migration 0026).
 *
 * Seule l'empreinte SHA-256 du jeton est stockée : une fuite de la table ne
 * donne aucun lien utilisable. La consommation est atomique — un UPDATE
 * conditionnel —, un lien ne sert donc qu'une fois, même cliqué deux fois.
 */
final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(int $userId, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO admin_password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:user, :hash, :expires, :now)'
        )->execute([
            'user' => $userId,
            'hash' => $tokenHash,
            'expires' => self::toSql($expiresAt),
            'now' => self::toSql($now),
        ]);
    }

    /** Compte visé par un lien encore valable, sans le consommer. */
    public function findUserId(string $tokenHash, DateTimeImmutable $now): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id FROM admin_password_resets
              WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now'
        );
        $statement->execute(['hash' => $tokenHash, 'now' => self::toSql($now)]);
        $userId = $statement->fetchColumn();

        return $userId === false ? null : (int) $userId;
    }

    /** Consomme le lien et renvoie le compte visé, ou null s'il n'est plus valable. */
    public function consume(string $tokenHash, DateTimeImmutable $now): ?int
    {
        $update = $this->pdo->prepare(
            'UPDATE admin_password_resets SET used_at = :now
              WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now2'
        );
        $update->execute(['now' => self::toSql($now), 'now2' => self::toSql($now), 'hash' => $tokenHash]);

        if ($update->rowCount() !== 1) {
            return null;
        }

        $select = $this->pdo->prepare('SELECT user_id FROM admin_password_resets WHERE token_hash = :hash');
        $select->execute(['hash' => $tokenHash]);
        $userId = $select->fetchColumn();

        return $userId === false ? null : (int) $userId;
    }

    /** Rend caducs tous les liens encore ouverts d'un compte : le mot de passe vient de changer. */
    public function revokeAll(int $userId, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE admin_password_resets SET used_at = :now WHERE user_id = :user AND used_at IS NULL'
        )->execute(['now' => self::toSql($now), 'user' => $userId]);
    }

    private static function toSql(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
