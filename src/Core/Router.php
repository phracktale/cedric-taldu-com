<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\MethodNotAllowedException;
use App\Core\Exception\NotFoundException;
use App\Core\Exception\RouteNotDeclared;

/**
 * Resolution d'une requete vers une route, a partir d'une table explicite.
 *
 * La table est aussi le point d'entree des tests de securite : CsrfTest et
 * AuthTest la parcourent au lieu de deviner les URL de l'application.
 */
final class Router
{
    /** @var list<Route> */
    private readonly array $routes;

    /** @var array<string, Route> indexee par nom + langue */
    private readonly array $byName;

    /**
     * @param list<Route> $routes
     */
    public function __construct(array $routes)
    {
        $byName = [];

        foreach ($routes as $route) {
            $key = self::key($route->name, $route->locale);

            if (isset($byName[$key])) {
                throw RouteNotDeclared::duplicate($route->name, $route->locale);
            }

            $byName[$key] = $route;
        }

        $this->routes = array_values($routes);
        $this->byName = $byName;
    }

    public function match(Request $request): RouteMatch
    {
        // HEAD est un GET sans corps : la meme route le sert, la couche
        // d'emission se charge de ne rien ecrire.
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;

        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route->pattern(), $request->path, $matches) !== 1) {
                continue;
            }

            if ($route->method === $method) {
                return new RouteMatch($route, self::namedParameters($matches));
            }

            if (!in_array($route->method, $allowed, true)) {
                $allowed[] = $route->method;
            }
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedException($allowed);
        }

        throw new NotFoundException('Aucune route ne correspond au chemin demandé.');
    }

    public function findByName(string $name, ?string $locale): Route
    {
        return $this->byName[self::key($name, $locale)]
            ?? throw RouteNotDeclared::forName($name, $locale);
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    private static function key(string $name, ?string $locale): string
    {
        return $name . "\0" . ($locale ?? '');
    }

    /**
     * @param array<array-key, string> $matches
     * @return array<string, string>
     */
    private static function namedParameters(array $matches): array
    {
        $parameters = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }
}
