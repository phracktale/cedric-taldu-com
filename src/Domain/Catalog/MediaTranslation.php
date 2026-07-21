<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;

/**
 * Textes d'un media pour une langue.
 *
 * Le texte alternatif est obligatoire a la publication d'une œuvre
 * (05-i18n-seo §5) : une image sans alt est inaccessible et invisible des
 * moteurs.
 */
final class MediaTranslation
{
    public function __construct(
        public readonly Locale $locale,
        public readonly string $alt,
        public readonly ?string $caption,
    ) {
    }
}
