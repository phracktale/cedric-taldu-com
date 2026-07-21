<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session PHP native, configuree strictement.
 *
 * Toute la configuration de 06-securite §4 est rassemblee dans options(), une
 * fonction pure : chaque garde-fou est verifiable sans demarrer de session.
 */
final class PhpSession implements SessionInterface
{
    public const COOKIE_NAME = CookieFactory::PREFIX . 'session';

    private bool $started = false;

    public function __construct(
        private readonly string $basePath,
        private readonly bool $secure,
        private readonly string $savePath,
    ) {
    }

    /**
     * @return array<string, string|int|bool>
     */
    public static function options(string $basePath, bool $secure, string $savePath): array
    {
        return [
            'name' => self::COOKIE_NAME,
            'save_path' => $savePath,
            'cookie_path' => $basePath === '' ? '/' : $basePath,
            'cookie_httponly' => true,
            'cookie_secure' => $secure,
            'cookie_samesite' => 'Lax',
            // Refuse un identifiant que le serveur n'a pas emis lui-meme :
            // c'est la parade a la fixation de session.
            'use_strict_mode' => true,
            // L'identifiant ne transite que par cookie : jamais dans une URL,
            // donc jamais dans un journal, un referent ou un lien partage.
            'use_only_cookies' => true,
            'use_trans_sid' => false,
            'sid_length' => 48,
            'sid_bits_per_character' => 5,
        ];
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0o770, true);
        }

        session_start(self::options($this->basePath, $this->secure, $this->savePath));
        $this->started = true;
    }

    public function id(): string
    {
        $id = session_id();

        return $id === false ? '' : $id;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $_SESSION[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function regenerateId(): void
    {
        session_regenerate_id(true);
    }
}
