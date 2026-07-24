<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;
use App\Domain\Slug;

/**
 * Textes d'un article pour une langue.
 *
 * `body` contient du HTML déjà assaini à l'écriture (06-securite §2) : la
 * lecture ne fait plus qu'afficher, via richText(). `excerpt` est du texte brut.
 */
final class PostTranslation
{
    public function __construct(
        public readonly Locale $locale,
        public readonly Slug $slug,
        public readonly string $title,
        public readonly ?string $excerpt,
        public readonly ?string $body,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
    ) {
    }
}
