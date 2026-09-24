<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Abonnés à la newsletter (revue du 2026-09-24).
 *
 * Une adresse = une ligne. S'abonner à nouveau après une désinscription reprend
 * un consentement NEUF (date, source, formulation) : c'est la preuve du dernier
 * accord qui compte. Les adresses sont normalisées (minuscules, sans espaces).
 */
final class NewsletterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function subscribe(
        string $email,
        string $locale,
        string $source,
        string $consentText,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO newsletter_subscribers (email, locale, source, consent_text, consented_at)
             VALUES (:email, :locale, :source, :text, :at)
             ON DUPLICATE KEY UPDATE
                locale = IF(unsubscribed_at IS NULL, locale, VALUES(locale)),
                source = IF(unsubscribed_at IS NULL, source, VALUES(source)),
                consent_text = IF(unsubscribed_at IS NULL, consent_text, VALUES(consent_text)),
                consented_at = IF(unsubscribed_at IS NULL, consented_at, VALUES(consented_at)),
                unsubscribed_at = NULL'
        );
        $statement->execute([
            'email' => self::normalize($email),
            'locale' => $locale,
            'source' => $source,
            'text' => mb_substr($consentText, 0, 255),
            'at' => self::toSql($now),
        ]);
    }

    /**
     * @return bool true si une adresse abonnée a été désinscrite
     */
    public function unsubscribe(string $email, DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE newsletter_subscribers SET unsubscribed_at = :at
              WHERE email = :email AND unsubscribed_at IS NULL'
        );
        $statement->execute(['at' => self::toSql($now), 'email' => self::normalize($email)]);

        return $statement->rowCount() > 0;
    }

    public function isActive(string $email): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :email AND unsubscribed_at IS NULL'
        );
        $statement->execute(['email' => self::normalize($email)]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return list<array{email: string, locale: string, source: string, consent_text: string, consented_at: string}>
     */
    public function findActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT email, locale, source, consent_text, consented_at
               FROM newsletter_subscribers
              WHERE unsubscribed_at IS NULL
              ORDER BY consented_at DESC, id DESC'
        );

        $rows = [];

        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            $rows[] = [
                'email' => (string) $row['email'],
                'locale' => (string) $row['locale'],
                'source' => (string) $row['source'],
                'consent_text' => (string) $row['consent_text'],
                'consented_at' => (string) $row['consented_at'],
            ];
        }

        return $rows;
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function toSql(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
