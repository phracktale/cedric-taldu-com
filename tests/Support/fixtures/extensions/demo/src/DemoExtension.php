<?php

declare(strict_types=1);

namespace ExtensionDemo;

use APress\Core\Container;
use APress\Core\Extension\AdminMenuItem;
use APress\Core\Extension\ExtensionInterface;
use APress\Core\Extension\Hooks;
use APress\Core\Route;
use PDO;

final class DemoExtension implements ExtensionInterface
{
    public function register(Hooks $hooks): void
    {
        $hooks->addRoute(new Route('admin.demo.index', 'GET', '/admin/demo', [DemoController::class, 'index']));
        $hooks->addAdminMenuItem(new AdminMenuItem('Démo', 'admin.demo.index'));
        $hooks->addService(
            DemoController::class,
            static fn (Container $c): DemoController => new DemoController($c->get(PDO::class)),
        );
    }
}
