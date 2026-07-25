<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Redirections 301 au changement de slug (05-i18n-seo §5).
 *
 * `from_path` et `to_path` sont des chemins SANS préfixe d'application, mais avec
 * le segment de langue (« /fr/galerie/ancien ») : c'est exactement `Request::path`,
 * ce qui rend le service au 404 immédiat. La clé `locale` reste pour les requêtes.
 *
 * Les chaînes sont APLATIES à l'écriture (« pas de rebond A→B→C ») : enregistrer
 * B→C réécrit tout A→B en A→C, et un chemin redevenu destination cesse de
 * rediriger.
 */
final class RedirectRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $locale, string $fromPath, string $toPath, DateTimeImmutable $now): void
    {
        // Rediriger un chemin vers lui-même n'a pas de sens.
        if ($fromPath === $toPath) {
            return;
        }

        // La destination redevient du contenu vivant : elle ne redirige plus.
        $live = $this->pdo->prepare('DELETE FROM redirects WHERE locale = :locale AND from_path = :to');
        $live->execute(['locale' => $locale, 'to' => $toPath]);

        // Aplatissement : tout ce qui pointait vers l'ancien chemin pointe
        // désormais vers la nouvelle cible, sans rebond.
        $flatten = $this->pdo->prepare(
            'UPDATE redirects SET to_path = :to WHERE locale = :locale AND to_path = :from'
        );
        $flatten->execute(['to' => $toPath, 'locale' => $locale, 'from' => $fromPath]);

        // Une seule redirection par chemin d'origine : ré-enregistrer met à jour.
        $upsert = $this->pdo->prepare(
            'INSERT INTO redirects (locale, from_path, to_path, hits, created_at)
             VALUES (:locale, :from, :to, 0, :now)
             ON DUPLICATE KEY UPDATE to_path = VALUES(to_path)'
        );
        $upsert->execute([
            'locale' => $locale,
            'from' => $fromPath,
            'to' => $toPath,
            'now' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    public function findTarget(string $locale, string $fromPath): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT to_path FROM redirects WHERE locale = :locale AND from_path = :from'
        );
        $statement->execute(['locale' => $locale, 'from' => $fromPath]);

        $target = $statement->fetchColumn();

        return $target === false ? null : (string) $target;
    }
}
