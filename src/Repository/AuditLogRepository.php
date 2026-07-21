<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Journal d'audit.
 *
 * 04-back-office §1 : « Toute action modifiant une donnee est tracee (acteur,
 * action, entite, differentiel des champs, IP hachee). » Conservation trois ans
 * (06-securite §9).
 *
 * Le journal ne doit jamais devenir lui-meme une fuite : l'IP y entre deja
 * hachee — c'est l'appelant qui la poivre — et le differentiel est construit par
 * le controleur, qui sait quels champs sont affichables. Aucune empreinte de mot
 * de passe, aucun secret TOTP, aucun code de secours n'y figure.
 */
final class AuditLogRepository
{
    private const SELECT = <<<'SQL'
        SELECT id, user_id, action, entity_type, entity_id, meta, ip_hash, created_at
        FROM audit_log
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $meta differentiel des champs
     */
    public function record(
        ?int $userId,
        string $action,
        ?string $entityType,
        ?int $entityId,
        ?array $meta,
        ?string $ipHash,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, meta, ip_hash, created_at)
             VALUES (:user, :action, :type, :entity, :meta, :ip, :now)'
        );

        $statement->execute([
            'user' => $userId,
            'action' => $action,
            'type' => $entityType,
            'entity' => $entityId,
            // JSON_THROW_ON_ERROR : un differentiel non encodable est un defaut
            // de programmation, pas une donnee a stocker en silence tronquee.
            'meta' => $meta === null ? null : json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => $ipHash,
            'now' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array{id: int, user_id: int|null, action: string, entity_type: string|null,
     *                    entity_id: int|null, meta: array<string, mixed>|null, ip_hash: string|null,
     *                    created_at: string}>
     */
    public function findRecent(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(self::SELECT . ' ORDER BY id DESC LIMIT :limit');
        // LIMIT doit etre lie en ENTIER : sans emulation des preparations, PDO
        // l'enverrait entre guillemets et MySQL refuserait la requete.
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $this->hydrateAll($statement->fetchAll());
    }

    /**
     * Historique d'une entite, pour l'afficher au bas de sa fiche.
     *
     * @return list<array{id: int, user_id: int|null, action: string, entity_type: string|null,
     *                    entity_id: int|null, meta: array<string, mixed>|null, ip_hash: string|null,
     *                    created_at: string}>
     */
    public function findForEntity(string $entityType, int $entityId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE entity_type = :type AND entity_id = :entity ORDER BY id DESC LIMIT :limit'
        );
        $statement->bindValue('type', $entityType);
        $statement->bindValue('entity', $entityId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $this->hydrateAll($statement->fetchAll());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{id: int, user_id: int|null, action: string, entity_type: string|null,
     *                    entity_id: int|null, meta: array<string, mixed>|null, ip_hash: string|null,
     *                    created_at: string}>
     */
    private function hydrateAll(array $rows): array
    {
        return array_values(array_map(
            static function (array $row): array {
                /** @var array<string, mixed>|null $meta */
                $meta = $row['meta'] === null
                    ? null
                    : json_decode((string) $row['meta'], true, 512, JSON_THROW_ON_ERROR);

                return [
                    'id' => (int) $row['id'],
                    'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
                    'action' => (string) $row['action'],
                    'entity_type' => $row['entity_type'] === null ? null : (string) $row['entity_type'],
                    'entity_id' => $row['entity_id'] === null ? null : (int) $row['entity_id'],
                    'meta' => $meta,
                    'ip_hash' => $row['ip_hash'] === null ? null : (string) $row['ip_hash'],
                    'created_at' => (string) $row['created_at'],
                ];
            },
            $rows,
        ));
    }
}
