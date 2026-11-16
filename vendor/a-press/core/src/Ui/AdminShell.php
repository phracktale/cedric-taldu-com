<?php

declare(strict_types=1);

namespace APress\Core\Ui;

use APress\Core\Auth\AdminSession;
use APress\Core\Auth\AuditTrail;
use APress\Core\ClockInterface;
use APress\Core\Config;
use APress\Core\Content\ContentModel;
use APress\Core\Csrf;
use APress\Core\Extension\Hooks;
use APress\Core\Request;
use APress\Core\Response;
use APress\Core\SessionInterface;
use DateTimeImmutable;

/**
 * Cadre commun des pages d'administration du cœur : compte connecté, jeton
 * CSRF, menu (rubriques du modèle de contenu, puis entrées des extensions),
 * message de retour, et en-têtes d'une interface privée (no-store, noindex).
 */
final class AdminShell
{
    private const FLASH = 'apress.flash';

    public function __construct(
        private readonly AdminView $view,
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly AdminSession $session,
        private readonly SessionInterface $store,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
        private readonly ContentModel $model,
        private readonly Hooks $hooks,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function page(Request $request, string $template, array $data = [], int $status = 200): Response
    {
        $user = $this->session->currentUser();
        $flash = $this->store->get(self::FLASH);
        $this->store->remove(self::FLASH);

        $html = $this->view->render($template, [
            'titre' => 'Administration',
            'utilisateur' => $user,
            'csrfToken' => $this->csrf->token(),
            'chemin' => $request->path,
            'menu' => $user === null ? [] : $this->menu(),
            'flash' => $flash,
            'isProduction' => $this->config->isProduction(),
            'env' => $this->config->env,
            ...$data,
        ], 'admin/layout');

        return Response::html($html, $status)
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function flash(string $message): void
    {
        $this->store->set(self::FLASH, $message);
    }

    public function audit(): AuditTrail
    {
        return $this->audit;
    }

    public function currentUserId(): ?int
    {
        return $this->session->currentUser()?->id;
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * @return list<array{label: string, items: list<array{chemin: string, libelle: string}>}>
     */
    private function menu(): array
    {
        $groups = [];
        foreach ($this->model->menu() as $key => $label) {
            $groups[$key] = ['label' => $label, 'items' => []];
        }

        foreach ($this->model->types() as $type) {
            $key = $type->menu ?? '_contenus';
            $groups[$key] ??= ['label' => 'Contenus', 'items' => []];
            $groups[$key]['items'][] = ['chemin' => '/admin/contenu/' . $type->name, 'libelle' => $type->label];
        }

        foreach ($this->hooks->adminMenu() as $item) {
            $target = null;
            foreach ($groups as $key => $group) {
                if ($group['label'] === $item->group) {
                    $target = $key;
                }
            }
            $target ??= '_extensions';
            $groups[$target] ??= ['label' => 'Extensions', 'items' => []];
            $groups[$target]['items'][] = ['chemin' => $this->routePath($item->routeName), 'libelle' => $item->label];
        }

        return array_values(array_filter($groups, static fn (array $g): bool => $g['items'] !== []));
    }

    private function routePath(string $routeName): string
    {
        foreach ($this->hooks->routes() as $route) {
            if ($route->name === $routeName) {
                return $route->path;
            }
        }

        return '/admin';
    }
}
