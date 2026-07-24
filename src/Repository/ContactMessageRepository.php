<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Contact\ContactMessage;
use App\Domain\Contact\MessageStatus;
use App\Domain\Locale;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Messages de contact (01-modele §6, 04-back-office §10).
 *
 * En écriture : le contrôleur a déjà validé et jugé le message (SpamGuard) ; ce
 * dépôt ne fait que persister. En lecture : la boîte de réception du back-office.
 * L'IP entre déjà hachée — c'est l'appelant qui la poivre — conformément à
 * 06-securite §9 : l'adresse en clair n'est jamais stockée.
 */
final class ContactMessageRepository
{
    private const SELECT = <<<'SQL'
        SELECT id, artwork_id, sender_name, sender_email, subject, body, locale,
               status, spam_score, ip_hash, user_agent, created_at
        FROM contact_messages
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(ContactMessage $message, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO contact_messages
                (artwork_id, sender_name, sender_email, subject, body, locale,
                 status, spam_score, ip_hash, user_agent, created_at)
             VALUES
                (:artwork, :name, :email, :subject, :body, :locale,
                 :status, :score, :ip, :agent, :now)'
        );

        $statement->execute([
            'artwork' => $message->artworkId,
            'name' => $message->senderName,
            'email' => $message->senderEmail,
            'subject' => $message->subject,
            'body' => $message->body,
            'locale' => $message->locale->value,
            'status' => $message->status->value,
            'score' => $message->spamScore,
            'ip' => $message->ipHash,
            'agent' => $message->userAgent,
            'now' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?ContactMessage
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE id = :id');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Messages, du plus récent au plus ancien, éventuellement filtrés par statut.
     *
     * @return list<ContactMessage>
     */
    public function findAll(?MessageStatus $status, int $limit, int $offset): array
    {
        if ($limit <= 0) {
            return [];
        }

        $sql = self::SELECT;

        if ($status !== null) {
            $sql .= ' WHERE status = :status';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);

        if ($status !== null) {
            $statement->bindValue('status', $status->value);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_map($this->hydrate(...), $statement->fetchAll()));
    }

    public function countByStatus(?MessageStatus $status): int
    {
        $sql = 'SELECT COUNT(*) FROM contact_messages';

        if ($status !== null) {
            $sql .= ' WHERE status = :status';
        }

        $statement = $this->pdo->prepare($sql);

        if ($status !== null) {
            $statement->bindValue('status', $status->value);
        }

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM contact_messages WHERE id = :id');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function updateStatus(int $id, MessageStatus $status): void
    {
        $statement = $this->pdo->prepare('UPDATE contact_messages SET status = :status WHERE id = :id');
        $statement->bindValue('status', $status->value);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ContactMessage
    {
        return new ContactMessage(
            id: (int) $row['id'],
            artworkId: $row['artwork_id'] === null ? null : (int) $row['artwork_id'],
            senderName: (string) $row['sender_name'],
            senderEmail: (string) $row['sender_email'],
            subject: (string) $row['subject'],
            body: (string) $row['body'],
            locale: Locale::from((string) $row['locale']),
            status: MessageStatus::from((string) $row['status']),
            spamScore: (int) $row['spam_score'],
            ipHash: $row['ip_hash'] === null ? null : (string) $row['ip_hash'],
            userAgent: $row['user_agent'] === null ? null : (string) $row['user_agent'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
        );
    }
}
