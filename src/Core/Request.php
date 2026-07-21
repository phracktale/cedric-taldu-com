<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\BadRequestException;

/**
 * Requete HTTP entrante, immuable.
 *
 * C'est le SEUL endroit du code autorise a lire les superglobales
 * (src/CLAUDE.md, section « Entrees » — test : SuperglobalTest). Partout ailleurs,
 * on recoit une Request construite, ce qui rend chaque comportement testable sans
 * serveur.
 *
 * Deux points de securite se jouent ici :
 *
 * 1. Le prefixe de chemin (09-environnements §3.2). APP_BASE_PATH explicite, sinon
 *    X-Forwarded-Prefix mais UNIQUEMENT derriere un proxy de confiance, sinon rien.
 * 2. Les en-tetes transferees (§4). X-Forwarded-Proto, -For et -Prefix ne sont lues
 *    que si REMOTE_ADDR figure dans TRUSTED_PROXIES. Sinon elles sont ignorees
 *    integralement : un client ne doit jamais pouvoir se declarer en HTTPS, changer
 *    le prefixe percu, ni usurper une IP pour contourner la limitation de debit.
 */
final class Request
{
    private const SAFE_METHODS = ['GET', 'HEAD'];

    /** Nombre de decodages successifs tentes pour demasquer un encodage multiple. */
    private const DECODE_ROUNDS = 4;

    /**
     * @param array<string, string> $headers cles en minuscules
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, string> $cookies
     * @param array<string, string> $attributes valeurs ajoutees par le noyau et
     *        les middlewares : langue, parametres de route, nonce CSP
     * @param array<string, list<UploadedFile>> $files fichiers televerses,
     *        indexes par nom de champ. Aucun controle n'est fait ici : c'est
     *        UploadValidator qui decide de leur sort (06-securite §5).
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly array $headers,
        public readonly string $clientIp,
        public readonly bool $secure,
        public readonly ?string $rawBody = null,
        public readonly array $attributes = [],
        public readonly array $files = [],
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files structure de $_FILES
     */
    public static function fromServer(
        Config $config,
        array $server,
        array $query = [],
        array $post = [],
        array $cookies = [],
        ?string $body = null,
        array $files = [],
    ): self {
        $headers = self::extractHeaders($server);
        $remoteAddr = self::stringOrEmpty($server['REMOTE_ADDR'] ?? null);
        $behindTrustedProxy = in_array($remoteAddr, $config->trustedProxies, true);

        $basePath = self::resolveBasePath($config, $headers, $behindTrustedProxy);
        $fullPath = self::resolvePath(self::stringOrEmpty($server['REQUEST_URI'] ?? '/'));

        return new self(
            method: strtoupper(self::stringOrEmpty($server['REQUEST_METHOD'] ?? 'GET')),
            path: self::stripBasePath($fullPath, $basePath),
            basePath: $basePath,
            query: self::onlyStrings($query),
            post: self::onlyStrings($post),
            cookies: self::onlyStrings($cookies),
            headers: $headers,
            clientIp: self::resolveClientIp($headers, $remoteAddr, $behindTrustedProxy),
            secure: self::resolveScheme($server, $headers, $behindTrustedProxy),
            rawBody: $body,
            files: self::normalizeFiles($files),
        );
    }

    /**
     * Unique point de contact avec les superglobales de PHP.
     */
    public static function fromGlobals(Config $config): self
    {
        $body = file_get_contents('php://input');

        return self::fromServer(
            $config,
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $body === false ? null : $body,
            $_FILES,
        );
    }

    /**
     * Fichiers televerses pour ce champ. Toujours une liste, meme pour un champ
     * simple : l'appelant n'a pas a distinguer les deux formes.
     *
     * @return list<UploadedFile>
     */
    public function files(string $name): array
    {
        return $this->files[$name] ?? [];
    }

    public function file(string $name): ?UploadedFile
    {
        return $this->files($name)[0] ?? null;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Une methode sure ne modifie rien cote serveur : elle est dispensee de jeton
     * CSRF, toutes les autres non (06-securite §3).
     */
    public function isSafeMethod(): bool
    {
        return in_array($this->method, self::SAFE_METHODS, true);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function input(string $name, ?string $default = null): ?string
    {
        return $this->post[$name] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function attribute(string $name, ?string $default = null): ?string
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * La requete restant immuable, un middleware qui enrichit le contexte rend
     * une nouvelle instance et la transmet au maillon suivant.
     *
     * @param array<string, string> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->basePath,
            $this->query,
            $this->post,
            $this->cookies,
            $this->headers,
            $this->clientIp,
            $this->secure,
            $this->rawBody,
            [...$this->attributes, ...$attributes],
            $this->files,
        );
    }

    // ------------------------------------------------------------------ interne

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function resolveBasePath(Config $config, array $headers, bool $behindTrustedProxy): string
    {
        if ($config->basePath !== '') {
            return $config->basePath;
        }

        if (!$behindTrustedProxy) {
            return '';
        }

        $forwarded = trim($headers['x-forwarded-prefix'] ?? '');
        if ($forwarded === '') {
            return '';
        }

        $normalized = '/' . trim($forwarded, '/');

        return $normalized === '/' ? '' : $normalized;
    }

    /**
     * Retire la chaine de requete, decode le chemin et refuse tout ce qui ressemble
     * a une traversee de repertoire, y compris sous encodage multiple.
     */
    private static function resolvePath(string $requestUri): string
    {
        $raw = $requestUri;
        $separator = strpos($raw, '?');
        if ($separator !== false) {
            $raw = substr($raw, 0, $separator);
        }

        $probe = $raw;
        for ($round = 0; $round < self::DECODE_ROUNDS; $round++) {
            $next = rawurldecode($probe);
            if ($next === $probe) {
                break;
            }
            $probe = $next;
        }

        if (str_contains($probe, "\0") || str_contains($probe, '\\')) {
            throw new BadRequestException('Chemin de requête invalide.');
        }

        foreach (explode('/', $probe) as $segment) {
            if ($segment === '..') {
                throw new BadRequestException('Chemin de requête invalide.');
            }
        }

        $path = rawurldecode($raw);

        return $path === '' ? '/' : $path;
    }

    private static function stripBasePath(string $path, string $basePath): string
    {
        if ($basePath === '' || !str_starts_with($path, $basePath)) {
            return $path;
        }

        $rest = substr($path, strlen($basePath));

        if ($rest === '') {
            return '/';
        }

        // « /cedric-taldu-autre » commence bien par « /cedric-taldu » sans etre
        // sous ce prefixe : on ne tronque que sur une frontiere de segment.
        return str_starts_with($rest, '/') ? $rest : $path;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function resolveClientIp(array $headers, string $remoteAddr, bool $behindTrustedProxy): string
    {
        if (!$behindTrustedProxy) {
            return $remoteAddr;
        }

        $forwarded = $headers['x-forwarded-for'] ?? '';
        if ($forwarded === '') {
            return $remoteAddr;
        }

        // Le proxy de confiance ajoute l'adresse reelle en FIN de liste
        // ($proxy_add_x_forwarded_for). Tout ce qui precede vient du client et
        // peut etre forge : seule la derniere entree valide fait foi.
        $candidates = array_reverse(array_map(trim(...), explode(',', $forwarded)));

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remoteAddr;
    }

    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     */
    private static function resolveScheme(array $server, array $headers, bool $behindTrustedProxy): bool
    {
        if ($behindTrustedProxy && isset($headers['x-forwarded-proto'])) {
            return strtolower(trim($headers['x-forwarded-proto'])) === 'https';
        }

        $https = strtolower(self::stringOrEmpty($server['HTTPS'] ?? ''));

        return $https !== '' && $https !== 'off';
    }

    /**
     * Ramene $_FILES a une forme unique : un tableau de listes.
     *
     * PHP presente un champ simple et un champ multiple de deux facons
     * differentes — la seconde transpose la structure. Normaliser ici evite que
     * chaque controleur ait a connaitre cette bizarrerie, et que l'un d'eux
     * l'oublie.
     *
     * @param  array<string, mixed> $files
     * @return array<string, list<UploadedFile>>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $uploads = UploadedFile::fromPhpUploads($entry);

            if ($uploads !== []) {
                $normalized[(string) $field] = $uploads;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private static function onlyStrings(array $values): array
    {
        $strings = [];

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $strings[(string) $key] = $value;
            }
        }

        return $strings;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
