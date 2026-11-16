<?php

declare(strict_types=1);

namespace APress\Core\Content;

use APress\Core\ClockInterface;
use InvalidArgumentException;

/**
 * Jeton d'aperçu d'un brouillon : `type.id.expiration.signature`.
 *
 * Signé en HMAC-SHA256 avec un secret du site, valable 15 minutes, lié à UNE
 * entrée. Il circule dans une URL (le bouton « Prévisualiser » ouvre le rendu
 * SSR du front) : fuité, il ne montre que ce brouillon-là, et pas longtemps.
 * La comparaison de signature est à temps constant.
 */
final class PreviewToken
{
    public const TTL_SECONDS = 900;
    private const MIN_SECRET_LENGTH = 32;
    private const MAX_LENGTH = 300;

    public function __construct(
        private readonly string $secret,
        private readonly ClockInterface $clock,
    ) {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException(sprintf('Le secret d’aperçu doit compter au moins %d caractères.', self::MIN_SECRET_LENGTH));
        }
    }

    public function issue(string $type, int $entryId): string
    {
        $payload = sprintf('%s.%d.%d', $type, $entryId, $this->clock->now()->getTimestamp() + self::TTL_SECONDS);

        return $payload . '.' . $this->sign($payload);
    }

    /**
     * @return array{type: string, id: int}|null
     */
    public function verify(string $token): ?array
    {
        if (strlen($token) > self::MAX_LENGTH || preg_match('/^([a-z][A-Za-z0-9]*)\.(\d{1,10})\.(\d{1,12})\.([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return null;
        }

        if (!hash_equals($this->sign($m[1] . '.' . $m[2] . '.' . $m[3]), $m[4])) {
            return null;
        }

        if ((int) $m[3] < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return ['type' => $m[1], 'id' => (int) $m[2]];
    }

    private function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->secret, true)), '+/', '-_'), '=');
    }
}
