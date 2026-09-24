<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Jetons de connexion à l'espace client (revue du 2026-09-24).
 *
 * Seule l'empreinte du jeton est stockée. La consommation est ATOMIQUE : un
 * UPDATE conditionnel marque le jeton utilisé, si bien que deux clics simultanés
 * sur le même lien n'ouvrent qu'une session.
 */
final class CustomerLoginRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(string $email, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO customer_login_tokens (email, token_hash, expires_at, created_at)
             VALUES (:email, :hash, :expires, :now)'
        )->execute([
            'email' => $email,
            'hash' => $tokenHash,
            'expires' => self::toSql($expiresAt),
            'now' => self::toSql($now),
        ]);
    }

    /**
     * Consomme un jeton valide et rend l'adresse qu'il ouvre, ou null.
     */
    public function consume(string $tokenHash, DateTimeImmutable $now): ?string
    {
        $update = $this->pdo->prepare(
            'UPDATE customer_login_tokens SET used_at = :now
              WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now2'
        );
        $update->execute(['now' => self::toSql($now), 'now2' => self::toSql($now), 'hash' => $tokenHash]);

        if ($update->rowCount() !== 1) {
            return null;
        }

        $select = $this->pdo->prepare('SELECT email FROM customer_login_tokens WHERE token_hash = :hash');
        $select->execute(['hash' => $tokenHash]);
        $email = $select->fetchColumn();

        return is_string($email) ? $email : null;
    }

    private static function toSql(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
