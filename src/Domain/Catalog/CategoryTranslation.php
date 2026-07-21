<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;
use App\Domain\Slug;

/**
 * Textes d'une rubrique pour une langue.
 *
 * description contient du HTML deja assaini a l'ecriture (06-securite §2).
 */
final class CategoryTranslation
{
    public function __construct(
        public readonly Locale $locale,
        public readonly Slug $slug,
        public readonly ?string $eyebrow,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
    ) {
    }
}
