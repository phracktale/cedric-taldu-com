<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Locale;
use JsonException;
use PDO;

/**
 * Reglages editables en back-office.
 *
 * Chaque valeur est un document JSON indexe par langue :
 *   { "fr": { … }, "en": { … } }
 *
 * Meme repli que le catalogue : le francais est obligatoire, l'anglais
 * facultatif (05-i18n-seo §3).
 *
 * Un reglage absent ou corrompu rend un tableau vide plutot qu'une erreur : le
 * site doit s'afficher sur une base de reglages neuve, et une saisie manuelle
 * malheureuse en base ne doit pas rendre le site inaccessible.
 */
final class SettingRepository
{
    /** @var array<string, array<string, mixed>> memoire de la requete */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed> document complet, toutes langues
     */
    public function json(string $key): array
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE `key` = :key');
        $statement->execute(['key' => $key]);

        $value = $statement->fetchColumn();

        return $this->cache[$key] = is_string($value) ? self::decode($value) : [];
    }

    /**
     * @return array<string, mixed> contenu de la langue, avec repli
     */
    public function forLocale(string $key, Locale $locale): array
    {
        return self::pick($this->json($key), $locale);
    }

    /**
     * Plusieurs reglages en UNE requete.
     *
     * L'accueil en lit huit : les demander un par un serait huit allers-retours
     * sur la page la plus consultee du site.
     *
     * @param list<string> $keys
     * @return array<string, array<string, mixed>>
     */
    public function manyForLocale(array $keys, Locale $locale): array
    {
        $result = [];

        if ($keys === []) {
            return $result;
        }

        $placeholders = [];
        $parameters = [];

        foreach (array_values(array_unique($keys)) as $index => $key) {
            $name = 'k' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $key;
            $result[$key] = [];
        }

        // Seule la liste de MARQUEURS est interpolee, jamais une valeur.
        $statement = $this->pdo->prepare(
            sprintf('SELECT `key`, value FROM settings WHERE `key` IN (%s)', implode(', ', $placeholders))
        );
        $statement->execute($parameters);

        foreach ($statement->fetchAll() as $row) {
            $key = (string) $row['key'];
            $document = is_string($row['value']) ? self::decode($row['value']) : [];

            $this->cache[$key] = $document;
            $result[$key] = self::pick($document, $locale);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function pick(array $document, Locale $locale): array
    {
        $content = $document[$locale->value] ?? $document[Locale::reference()->value] ?? [];

        return is_array($content) ? $content : [];
    }
}
