<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;
use App\Domain\Slug;

/**
 * Textes d'une serie pour une langue.
 */
final class SeriesTranslation
{
    public function __construct(
        public readonly Locale $locale,
        public readonly Slug $slug,
        public readonly string $title,
        public readonly ?string $description,
    ) {
    }
}
