<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Core\LoggerInterface;
use App\Core\LogLevel;

/**
 * Journal en memoire : permet d'affirmer qu'un evenement de securite a bien ete
 * consigne, et surtout qu'il ne contient ni secret ni donnee personnelle.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: LogLevel, message: string, context: array<string, string|int|float|bool|null>}> */
    public array $entries = [];

    /**
     * @param array<string, string|int|float|bool|null> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): string
    {
        $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];

        return str_pad((string) count($this->entries), 16, '0', STR_PAD_LEFT);
    }
}
