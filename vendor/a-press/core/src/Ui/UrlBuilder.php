<?php

declare(strict_types=1);

namespace APress\Core\Ui;

/**
 * Adresses internes, toujours sous le préfixe du site (/saga, /cedric-taldu
 * ou rien) : aucun gabarit n'écrit un chemin absolu en dur.
 */
final class UrlBuilder
{
    public function __construct(
        public readonly string $basePath,
        private readonly string $publicPath = '',
    ) {
    }

    public function path(string $path): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    public function admin(string $path = ''): string
    {
        return $this->basePath . '/admin' . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /**
     * Fichier statique du cœur, publié sous public/assets/apress/ par
     * Assets::publish(). L'empreinte du fichier sert de version de cache.
     */
    public function asset(string $file): string
    {
        $url = $this->basePath . '/assets/apress/' . ltrim($file, '/');
        $disk = $this->publicPath . '/assets/apress/' . ltrim($file, '/');

        return is_file($disk) ? $url . '?v=' . substr((string) md5_file($disk), 0, 8) : $url;
    }
}
