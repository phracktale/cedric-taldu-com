<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\SessionUnavailable;

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

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function id(): string
    {
        $this->start();
        $id = session_id();

        return $id === false ? '' : $id;
    }

    public function has(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $_SESSION);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->start();
        $value = $_SESSION[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $this->start();
        $_SESSION = [];
    }

    public function regenerateId(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    /**
     * Demarrage PARESSEUX.
     *
     * Une lecture anonyme ne doit poser aucun cookie : sans cela, chaque
     * visiteur d'une page publique repart avec un ct_session dont il n'a aucun
     * usage, et la reponse devient impossible a mettre en cache. La session ne
     * demarre donc qu'au premier acces reel — un formulaire, un panier, une
     * connexion.
     */
    private function start(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        $this->assertUsableSavePath();

        session_start(self::options($this->basePath, $this->secure, $this->savePath));
        $this->started = true;
    }

    /**
     * Refuse de demarrer sur un stockage inutilisable.
     *
     * Sans ce controle, PHP se contente d'une alerte et repart d'une session
     * NEUVE a chaque requete : le jeton CSRF change entre l'affichage du
     * formulaire et son envoi, et l'utilisateur ne voit qu'un « 419 Formulaire
     * expiré » qui n'a rien a voir avec le CSRF.
     *
     * C'est exactement ce qui s'est produit en preproduction le 2026-07-21 :
     * storage/ est monte depuis l'hote, ce qui masque le chown de l'image, et le
     * conteneur ne pouvait plus rien y ecrire. Une session absente doit
     * s'annoncer comme telle.
     */
    private function assertUsableSavePath(): void
    {
        // Teste AVANT le mkdir : tenter de creer un repertoire la ou un fichier
        // existe deja n'emet qu'une alerte « File exists » et rend false, ce qui
        // ferait remonter un motif faux.
        if (file_exists($this->savePath) && !is_dir($this->savePath)) {
            throw SessionUnavailable::forPath($this->savePath, 'un fichier occupe ce chemin');
        }

        if (!is_dir($this->savePath) && !mkdir($this->savePath, 0o770, true) && !is_dir($this->savePath)) {
            throw SessionUnavailable::forPath($this->savePath, 'répertoire absent et impossible à créer');
        }

        if (!is_writable($this->savePath)) {
            throw SessionUnavailable::forPath($this->savePath, 'répertoire non inscriptible');
        }
    }
}
