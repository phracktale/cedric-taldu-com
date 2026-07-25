<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Repository\RedirectRepository;
use App\Service\I18n\UrlGenerator;
use DateTimeImmutable;

/**
 * Enregistre une redirection 301 quand un slug publié change (05-i18n-seo §5).
 *
 * Appelé par les contrôleurs d'administration à la mise à jour d'une entité à
 * slug (rubrique, œuvre, article). Il compare les slugs par langue et, pour
 * chacun qui change, pose une redirection de l'ANCIEN chemin vers le nouveau —
 * dans le même format que `Request::path` (sans préfixe d'application), pour que
 * le middleware la serve directement.
 */
final class SlugHistory
{
    public function __construct(
        private readonly RedirectRepository $redirects,
        private readonly UrlGenerator $url,
    ) {
    }

    /**
     * @param array<string, array<string, string|null>> $before traductions AVANT (locale => champs)
     * @param array<string, array<string, string|null>> $after  traductions APRÈS
     */
    public function capture(
        string $routeName,
        array $before,
        array $after,
        string $basePath,
        DateTimeImmutable $now,
    ): void {
        foreach ($after as $locale => $fields) {
            $oldSlug = $before[$locale]['slug'] ?? null;
            $newSlug = $fields['slug'] ?? null;

            if (!is_string($oldSlug) || !is_string($newSlug) || $oldSlug === '' || $newSlug === '') {
                continue;
            }

            if ($oldSlug === $newSlug) {
                continue;
            }

            $this->redirects->record(
                $locale,
                $this->localPath($routeName, $locale, $oldSlug, $basePath),
                $this->localPath($routeName, $locale, $newSlug, $basePath),
                $now,
            );
        }
    }

    /**
     * Chemin sans préfixe d'application (« /fr/galerie/slug »), tel que stocké.
     */
    private function localPath(string $routeName, string $locale, string $slug, string $basePath): string
    {
        $full = $this->url->route($routeName, ['locale' => $locale, 'slug' => $slug]);

        return $basePath === '' ? $full : substr($full, strlen($basePath));
    }
}
