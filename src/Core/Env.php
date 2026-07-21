<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\MissingEnvironmentVariable;

/**
 * Acces en lecture seule aux variables d'environnement.
 *
 * L'objet est immuable et construit a partir d'un tableau : il ne lit aucune
 * superglobale et reste testable sans systeme de fichiers. C'est l'appelant
 * (public/index.php, bin/*) qui fournit l'environnement systeme via getenv().
 *
 * L'environnement systeme prime sur le fichier : Docker Compose fixe APP_ENV et
 * APP_BASE_PATH par `environment:` alors que le code monte peut contenir un .env
 * de developpement.
 */
final class Env
{
    /**
     * @param array<string, string> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<string, string> $systemEnvironment valeurs issues de getenv()
     */
    public static function load(string $path, array $systemEnvironment = []): self
    {
        $fromFile = [];

        // Un fichier absent n'est pas une erreur : en conteneur, toute la
        // configuration peut venir de l'environnement systeme. Une variable
        // requise manquante, elle, leve une exception a la premiere lecture.
        if (is_file($path) && is_readable($path)) {
            $contents = file_get_contents($path);
            if ($contents !== false) {
                $fromFile = self::parse($contents);
            }
        }

        return new self([...$fromFile, ...$systemEnvironment]);
    }

    /**
     * @param array<string, string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            if ($key === '') {
                continue;
            }

            $values[$key] = self::parseValue(substr($line, $separator + 1));
        }

        return $values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): string
    {
        if (!array_key_exists($key, $this->values)) {
            throw MissingEnvironmentVariable::forKey($key);
        }

        return $this->values[$key];
    }

    public function getOptional(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Une valeur entre guillemets est prise telle quelle : elle peut contenir des
     * espaces et des « # ». Hors guillemets, un « # » precede d'une espace ouvre
     * un commentaire de fin de ligne.
     */
    private static function parseValue(string $raw): string
    {
        $value = trim($raw);

        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                return substr($value, 1, -1);
            }
        }

        $withoutComment = preg_replace('/\s+#.*$/', '', $value);

        return trim($withoutComment ?? $value);
    }
}
