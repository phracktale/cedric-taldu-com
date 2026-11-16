<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Aucun générateur configuré (développement, site sans front statique).
 */
final class NullPublishNotifier implements PublishNotifier
{
    public function notify(string $type, int $id, string $event): void
    {
    }
}
