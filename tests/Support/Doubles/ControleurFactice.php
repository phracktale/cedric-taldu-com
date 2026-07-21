<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Core\Request;
use App\Core\Response;

/**
 * Controleur sans effet, employe partout ou un test a besoin d'une cible de route
 * valide sans que le comportement du controleur entre en jeu.
 */
final class ControleurFactice
{
    public function index(Request $request): Response
    {
        return Response::html('index');
    }

    public function show(Request $request): Response
    {
        return Response::html('show');
    }

    public function store(Request $request): Response
    {
        return Response::html('store');
    }
}
