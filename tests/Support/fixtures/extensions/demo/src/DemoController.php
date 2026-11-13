<?php

declare(strict_types=1);

namespace ExtensionDemo;

use APress\Core\Request;
use APress\Core\Response;
use PDO;

final class DemoController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function index(Request $request): Response
    {
        $statement = $this->pdo->prepare('SELECT JSON_UNQUOTE(value) FROM settings WHERE `key` = :key');
        $statement->execute(['key' => 'demo.message']);

        return Response::html('<p>' . htmlspecialchars((string) $statement->fetchColumn(), ENT_QUOTES) . '</p>');
    }
}
