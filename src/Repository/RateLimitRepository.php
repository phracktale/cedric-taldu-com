<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Compteurs de limitation de debit.
 *
 * Le schema (01-modele §1) porte une cle primaire (bucket_key, window_start) et
 * un compteur. La fenetre glissante s'obtient en decoupant le temps en TRANCHES
 * fixes et en sommant celles qui tombent dans la fenetre : c'est ce decoupage
 * qui rend l'increment atomique — un seul INSERT ... ON DUPLICATE KEY UPDATE,
 * sans lecture prealable, donc sans course entre deux requetes simultanees.
 */
final class RateLimitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Incremente la tranche courante et rend le total de la fenetre.
     *
     * L'increment et la somme sont deux requetes : entre les deux, une autre
     * requete peut incrementer a son tour. Le total rendu est alors PLUS GRAND
     * que le sien, jamais plus petit — l'erreur va dans le sens de la limitation
     * et non dans celui du contournement.
     */
    public function hitAndCount(string $bucketKey, DateTimeImmutable $sliceStart, DateTimeImmutable $windowStart): int
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, window_start, hits)
             VALUES (:key, :slice, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        );
        $insert->execute(['key' => $bucketKey, 'slice' => self::toSql($sliceStart)]);

        return $this->countSince($bucketKey, $windowStart);
    }

    public function countSince(string $bucketKey, DateTimeImmutable $windowStart): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(hits), 0) FROM rate_limits
              WHERE bucket_key = :key AND window_start >= :since'
        );
        $statement->execute(['key' => $bucketKey, 'since' => self::toSql($windowStart)]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Purge des tranches sorties de toute fenetre.
     *
     * Idempotente et rejouable : aucun worker n'est disponible sur mutualise
     * (CLAUDE.md), la purge est declenchee par un endpoint protege par jeton et
     * peut donc etre appelee deux fois de suite.
     *
     * @return int nombre de tranches effacees
     */
    public function purgeBefore(DateTimeImmutable $threshold): int
    {
        $statement = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < :threshold');
        $statement->execute(['threshold' => self::toSql($threshold)]);

        return $statement->rowCount();
    }

    private static function toSql(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
