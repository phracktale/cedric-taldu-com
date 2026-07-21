<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Admin\AdminUser;
use App\Domain\Admin\Role;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Acces aux comptes d'administration et a leurs codes de secours.
 *
 * Les dates sont stockees en UTC (ARCHITECTURE §4) et relues en UTC : sans le
 * fuseau explicite, DateTimeImmutable adopterait celui du serveur, et un verrou
 * pose a 09h30 UTC paraitrait expirer a 11h45 sur une machine reglee a Paris.
 */
final class UserRepository
{
    private const SELECT = <<<'SQL'
        SELECT id, email, password_hash, display_name, role, totp_secret, totp_last_counter,
               failed_attempts, locked_until, last_login_at
        FROM users
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------- lecture

    public function findByEmail(string $email): ?AdminUser
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);

        return $this->hydrate($statement->fetch());
    }

    public function findById(int $id): ?AdminUser
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function countAll(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM users');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    // ------------------------------------------------------------ ecriture

    /**
     * Persiste ce que le domaine a decide : compteur d'echecs, verrou, derniere
     * visite. L'adresse, le role et l'empreinte ne passent PAS par ici — ce sont
     * des operations d'administration, chacune tracee separement.
     */
    public function save(AdminUser $user): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
                SET failed_attempts = :attempts,
                    locked_until = :locked,
                    last_login_at = :seen,
                    updated_at = NOW()
              WHERE id = :id'
        );

        $statement->execute([
            'attempts' => $user->failedAttempts,
            'locked' => self::toSql($user->lockedUntil),
            'seen' => self::toSql($user->lastLoginAt),
            'id' => $user->id,
        ]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id'
        );

        $statement->execute(['hash' => $hash, 'id' => $id]);
    }

    /**
     * Un secret NULL desactive la 2FA. Les codes de secours ne sont pas effaces
     * ici : leur sort appartient a l'appelant, qui sait s'il desactive la 2FA
     * ou s'il change de telephone.
     */
    public function updateTotpSecret(int $id, ?string $secret): void
    {
        // Le compteur anti-rejeu repart avec le secret : un nouveau secret n'a
        // pas d'historique, et le garder interdirait les premieres fenetres.
        $statement = $this->pdo->prepare(
            'UPDATE users SET totp_secret = :secret, totp_last_counter = NULL, updated_at = NOW() WHERE id = :id'
        );

        $statement->execute(['secret' => $secret, 'id' => $id]);
    }

    /**
     * Memorise la fenetre TOTP qui vient d'etre acceptee (RFC 6238 §5.2).
     *
     * La condition sur l'ancienne valeur rend l'ecriture monotone : deux
     * requetes simultanees ne peuvent pas faire RECULER le compteur, ce qui
     * rouvrirait une fenetre deja consommee.
     */
    public function updateTotpCounter(int $id, int $counter): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
                SET totp_last_counter = :counter, updated_at = NOW()
              WHERE id = :id AND (totp_last_counter IS NULL OR totp_last_counter < :floor)'
        );

        // Le meme nom de parametre ne peut pas etre lie deux fois hors emulation
        // des preparations : d'ou :counter et :floor.
        $statement->execute(['counter' => $counter, 'id' => $id, 'floor' => $counter]);
    }

    public function create(string $email, string $passwordHash, string $displayName, Role $role, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, role, created_at, updated_at)
             VALUES (:email, :hash, :name, :role, :now, :now2)'
        );

        // Le meme nom de parametre ne peut pas etre lie deux fois hors emulation
        // des preparations : d'ou :now et :now2.
        $statement->execute([
            'email' => $email,
            'hash' => $passwordHash,
            'name' => $displayName,
            'role' => $role->value,
            'now' => self::toSql($now),
            'now2' => self::toSql($now),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ----------------------------------------------------- codes de secours

    /**
     * Remplace l'integralite des codes d'un compte.
     *
     * Regenerer invalide les anciens : une feuille perdue ne doit pas rester
     * valable indefiniment.
     *
     * @param list<string> $hashes empreintes, jamais les codes en clair
     */
    public function replaceBackupCodes(int $userId, array $hashes, DateTimeImmutable $now): void
    {
        $delete = $this->pdo->prepare('DELETE FROM user_backup_codes WHERE user_id = :id');
        $delete->execute(['id' => $userId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO user_backup_codes (user_id, code_hash, created_at) VALUES (:id, :hash, :now)'
        );

        foreach ($hashes as $hash) {
            $insert->execute(['id' => $userId, 'hash' => $hash, 'now' => self::toSql($now)]);
        }
    }

    /**
     * Consomme un code, ou refuse.
     *
     * L'unicite d'usage ne peut PAS reposer sur un « lire puis ecrire » : deux
     * requetes simultanees liraient toutes deux un code inutilise et
     * l'accepteraient toutes deux. Elle repose sur un UPDATE conditionnel — la
     * base n'accorde la ligne qu'a une seule des deux — et sur le nombre de
     * lignes affectees.
     *
     * Le filtre sur user_id est indispensable : les empreintes sont poivrees
     * mais pas liees au compte, un code fuite ouvrirait sinon n'importe lequel.
     */
    public function consumeBackupCode(int $userId, string $hash, DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_backup_codes
                SET used_at = :now
              WHERE user_id = :id AND code_hash = :hash AND used_at IS NULL'
        );

        $statement->execute(['now' => self::toSql($now), 'id' => $userId, 'hash' => $hash]);

        return $statement->rowCount() === 1;
    }

    public function countUnusedBackupCodes(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_backup_codes WHERE user_id = :id AND used_at IS NULL'
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    // ------------------------------------------------------------- interne

    /**
     * @param array<string, mixed>|false $row
     */
    private function hydrate(array|false $row): ?AdminUser
    {
        if ($row === false) {
            return null;
        }

        // Un role inconnu en base — ajoute a la main, migration partielle — ne
        // doit pas faire tomber la connexion : on retombe sur le moins
        // privilegie des deux.
        $role = Role::tryFrom((string) $row['role']) ?? Role::Editor;

        return new AdminUser(
            id: (int) $row['id'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            displayName: (string) $row['display_name'],
            role: $role,
            totpSecret: $row['totp_secret'] === null ? null : (string) $row['totp_secret'],
            failedAttempts: (int) $row['failed_attempts'],
            lockedUntil: self::toDate($row['locked_until']),
            lastLoginAt: self::toDate($row['last_login_at']),
            totpLastCounter: $row['totp_last_counter'] === null ? null : (int) $row['totp_last_counter'],
        );
    }

    private static function toDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null
            ? null
            : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    private static function toSql(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
