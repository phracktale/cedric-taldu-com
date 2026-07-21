<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\InvalidConfiguration;

/**
 * Configuration applicative typee, validee une fois au demarrage.
 *
 * Tout ce qui vient de l'environnement est normalise et controle ici, de sorte
 * que le reste du code manipule des valeurs sures : un prefixe de chemin toujours
 * de la forme « » ou « /xxx », une liste de langues non vide, un poivre exploitable.
 */
final class Config
{
    public const ENV_DEV = 'dev';
    public const ENV_PREPROD = 'preprod';
    public const ENV_PROD = 'prod';

    private const ENVIRONMENTS = [self::ENV_DEV, self::ENV_PREPROD, self::ENV_PROD];

    /** Longueur minimale du poivre de hachage, en caracteres. */
    private const PEPPER_MIN_LENGTH = 32;

    private const TRUTHY = ['1', 'true', 'on', 'yes'];

    /**
     * @param list<string> $locales
     * @param list<string> $trustedProxies
     */
    public function __construct(
        public readonly string $env,
        public readonly bool $debug,
        public readonly string $url,
        public readonly string $basePath,
        public readonly string $defaultLocale,
        public readonly array $locales,
        public readonly array $trustedProxies,
        public readonly string $securityPepper,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        $environment = trim($env->get('APP_ENV'));
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw InvalidConfiguration::forKey(
                'APP_ENV',
                'valeurs attendues : ' . implode(', ', self::ENVIRONMENTS) . '.'
            );
        }

        $locales = self::parseList($env->get('APP_LOCALES'));
        if ($locales === []) {
            throw InvalidConfiguration::forKey('APP_LOCALES', 'au moins une langue est requise.');
        }

        $defaultLocale = trim($env->get('APP_DEFAULT_LOCALE'));
        if (!in_array($defaultLocale, $locales, true)) {
            throw InvalidConfiguration::forKey(
                'APP_DEFAULT_LOCALE',
                'la langue par défaut doit figurer dans APP_LOCALES.'
            );
        }

        $pepper = trim($env->get('SECURITY_PEPPER'));
        if (strlen($pepper) < self::PEPPER_MIN_LENGTH) {
            throw InvalidConfiguration::forKey(
                'SECURITY_PEPPER',
                sprintf('au moins %d caractères aléatoires sont requis.', self::PEPPER_MIN_LENGTH)
            );
        }

        return new self(
            env: $environment,
            // En production, le mode de debogage est force a false quoi qu'en dise
            // la configuration : une erreur de deploiement ne doit pas suffire a
            // faire fuir une trace d'exception (06-securite §10).
            debug: $environment !== self::ENV_PROD
                && in_array(strtolower(trim($env->get('APP_DEBUG'))), self::TRUTHY, true),
            url: rtrim(trim($env->get('APP_URL')), '/'),
            basePath: self::normalizeBasePath($env->get('APP_BASE_PATH')),
            defaultLocale: $defaultLocale,
            locales: $locales,
            trustedProxies: self::parseList($env->get('TRUSTED_PROXIES')),
            securityPepper: $pepper,
        );
    }

    public function isProduction(): bool
    {
        return $this->env === self::ENV_PROD;
    }

    /**
     * Ramene le prefixe a l'une des deux formes utilisables telles quelles dans une
     * URL : la chaine vide (production, site a la racine) ou « /segment » sans slash
     * final (preprod sous /cedric-taldu).
     */
    private static function normalizeBasePath(string $raw): string
    {
        $path = trim($raw);
        $path = '/' . trim($path, '/');

        return $path === '/' ? '' : $path;
    }

    /**
     * @return list<string>
     */
    private static function parseList(string $raw): array
    {
        $items = array_map(trim(...), explode(',', $raw));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }
}
