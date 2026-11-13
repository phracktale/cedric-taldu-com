<?php

declare(strict_types=1);

namespace APress\Core\Extension;

use APress\Core\Route;
use LogicException;

/**
 * Ce que les extensions actives ajoutent au site, collecté au démarrage.
 *
 * Les points d'accroche sont typés : une extension ajoute un objet Route, une
 * entrée de menu ou une fabrique de service, jamais une chaîne libre qu'un
 * site devrait interpréter. Le lot 4 ajoutera les dossiers de gabarits, et le
 * lot 5 les îlots front et les routes d'API.
 */
final class Hooks
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var list<AdminMenuItem> */
    private array $adminMenu = [];

    /** @var array<string, callable> */
    private array $services = [];

    /** @var list<string> */
    private array $booted = [];

    /**
     * Appelé par ExtensionManager une fois register() réussi. Le site s'en sert
     * pour masquer ce qui dépend d'une extension inactive (lien vers le panier…).
     */
    public function markBooted(string $name): void
    {
        $this->booted[] = $name;
    }

    public function isBooted(string $name): bool
    {
        return in_array($name, $this->booted, true);
    }

    /**
     * @return list<string>
     */
    public function booted(): array
    {
        return $this->booted;
    }

    public function addRoute(Route $route): void
    {
        $this->routes[] = $route;
    }

    public function addAdminMenuItem(AdminMenuItem $item): void
    {
        $this->adminMenu[] = $item;
    }

    public function addService(string $id, callable $factory): void
    {
        if (array_key_exists($id, $this->services)) {
            throw new LogicException(sprintf('Service « %s » déclaré deux fois par les extensions.', $id));
        }

        $this->services[$id] = $factory;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return list<AdminMenuItem> triées par position, puis par ordre de déclaration
     */
    public function adminMenu(): array
    {
        $items = $this->adminMenu;
        usort($items, static fn (AdminMenuItem $a, AdminMenuItem $b): int => $a->position <=> $b->position);

        return $items;
    }

    /**
     * @return array<string, callable>
     */
    public function services(): array
    {
        return $this->services;
    }
}
