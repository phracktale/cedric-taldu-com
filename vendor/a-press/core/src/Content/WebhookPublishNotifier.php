<?php

declare(strict_types=1);

namespace APress\Core\Content;

use APress\Core\LoggerInterface;
use APress\Core\LogLevel;
use Closure;
use Throwable;

/**
 * Appelle le service de build (POST JSON, jeton dans X-Build-Token).
 *
 * Le builder regroupe les demandes rapprochées et répond 202 aussitôt : un
 * délai court suffit, et un builder injoignable ne bloque pas l'éditeur — la
 * publication est enregistrée, l'échec est journalisé (sans le jeton) et la
 * prochaine publication relancera la génération.
 */
final class WebhookPublishNotifier implements PublishNotifier
{
    private const TIMEOUT_SECONDS = 3;

    /** @var Closure(string, list<string>, string): int */
    private readonly Closure $send;

    /**
     * @param (callable(string, list<string>, string): int)|null $send transport HTTP, remplacé en test
     */
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly LoggerInterface $logger,
        ?callable $send = null,
    ) {
        $this->send = $send !== null ? Closure::fromCallable($send) : self::post(...);
    }

    public function notify(string $type, int $id, string $event): void
    {
        $body = (string) json_encode(['type' => $type, 'id' => $id, 'event' => $event]);
        $headers = ['Content-Type: application/json', 'X-Build-Token: ' . $this->token];

        try {
            $status = ($this->send)($this->url, $headers, $body);
            if ($status < 200 || $status >= 300) {
                $this->logger->log(LogLevel::Warning, 'build.notify_refused', ['status' => $status, 'event' => $event]);
            }
        } catch (Throwable $exception) {
            $this->logger->log(LogLevel::Warning, 'build.notify_failed', ['error' => $exception->getMessage(), 'event' => $event]);
        }
    }

    /**
     * @param list<string> $headers
     */
    private static function post(string $url, array $headers, string $body): int
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        // PHP ne la remplit que si le serveur a répondu : injoignable, elle resterait indéfinie.
        $http_response_header = [];
        $result = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header;
        if ($result === false && $responseHeaders === []) {
            throw new \RuntimeException('builder injoignable');
        }

        return preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0] ?? '', $m) === 1 ? (int) $m[1] : 0;
    }
}
