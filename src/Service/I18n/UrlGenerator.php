<?php

declare(strict_types=1);

namespace App\Service\I18n;

use App\Core\Config;
use App\Core\Exception\AssetNotFound;
use App\Core\Exception\InvalidRouteParameter;
use App\Core\Router;

/**
 * Production de toutes les URL du site.
 *
 * Aucune URL n'est ecrite en dur nulle part (CLAUDE.md §4) : le site tourne sous
 * /cedric-taldu en preproduction et a la racine en production, et un chemin ecrit
 * a la main casse la preprod sans casser la prod — donc passe les tests locaux et
 * se decouvre en ligne.
 *
 * Le prefixe est celui RESOLU PAR LA REQUETE, pas celui de la configuration :
 * derriere Heimdall, il peut venir de X-Forwarded-Prefix (09-environnements §3.2).
 */
final class UrlGenerator
{
    /** @var array<string, string> empreintes d'assets deja calculees */
    private array $assetVersions = [];

    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly string $basePath,
        private readonly string $publicPath,
    ) {
    }

    /**
     * URL interne, prefixe compris.
     *
     * La cle « locale » choisit la variante linguistique de la route ; les cles
     * restantes qui ne correspondent pas a un parametre du chemin deviennent la
     * chaine de requete.
     *
     * @param array<string, string|int> $parameters
     */
    public function route(string $name, array $parameters = []): string
    {
        return $this->basePath . $this->pathFor($name, $parameters);
    }

    /**
     * URL absolue, construite depuis APP_URL et jamais depuis l'en-tete Host :
     * sert aux liens canoniques, au sitemap, aux e-mails et aux retours Stripe
     * (05-i18n-seo §5, 09-environnements §3.8).
     *
     * @param array<string, string|int> $parameters
     */
    public function absolute(string $name, array $parameters = []): string
    {
        return $this->config->url . $this->pathFor($name, $parameters);
    }

    /**
     * URL d'un fichier de public/assets/, suffixee d'une empreinte de contenu.
     *
     * L'empreinte ne change que si le fichier change : un deploiement qui ne
     * touche pas au CSS n'invalide pas le cache des visiteurs.
     */
    public function asset(string $path): string
    {
        return $this->basePath . '/assets/' . $path . '?v=' . $this->assetVersion($path);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function pathFor(string $name, array $parameters): string
    {
        $locale = $parameters['locale'] ?? null;
        unset($parameters['locale']);

        $route = $this->router->findByName($name, $locale === null ? null : (string) $locale);
        $path = $route->path;

        foreach ($route->parameterNames() as $parameter) {
            if (!isset($parameters[$parameter])) {
                throw InvalidRouteParameter::missing($name, $parameter);
            }

            $value = (string) $parameters[$parameter];
            $requirement = $route->requirements[$parameter] ?? null;

            if ($requirement !== null && preg_match('#^' . $requirement . '$#D', $value) !== 1) {
                throw InvalidRouteParameter::violatesRequirement($name, $parameter);
            }

            $path = str_replace('{' . $parameter . '}', rawurlencode($value), $path);
            unset($parameters[$parameter]);
        }

        return $parameters === [] ? $path : $path . '?' . http_build_query($parameters);
    }

    private function assetVersion(string $path): string
    {
        if (isset($this->assetVersions[$path])) {
            return $this->assetVersions[$path];
        }

        $file = $this->resolveAssetFile($path);
        $hash = hash_file('sha256', $file);

        if ($hash === false) {
            throw AssetNotFound::forPath($path);
        }

        return $this->assetVersions[$path] = substr($hash, 0, 8);
    }

    /**
     * Le chemin d'un asset est ecrit par un developpeur, jamais fourni par un
     * client. Il est malgre tout verifie : le jour ou il le deviendrait, la
     * verification serait deja en place et testee.
     */
    private function resolveAssetFile(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || $path[0] === '/') {
            throw AssetNotFound::forPath($path);
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '') {
                throw AssetNotFound::forPath($path);
            }
        }

        $root = realpath($this->publicPath . '/assets');
        $file = realpath($this->publicPath . '/assets/' . $path);

        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw AssetNotFound::forPath($path);
        }

        return $file;
    }
}
