<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Core\Request;
use App\Core\Response;
use RuntimeException;

/**
 * Controleur qui leve une exception dont le message ressemble a ce qui fuit
 * reellement en production : une chaine de connexion PDO.
 *
 * Employe par ErrorLeakTest pour verifier que rien de tout cela n'atteint le
 * navigateur (06-securite §10).
 */
final class ControleurQuiEchoue
{
    public const MESSAGE = 'mysql:host=db;dbname=cedrictaldu';

    public function index(Request $request): Response
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
