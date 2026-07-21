<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;
use App\Domain\Slug;

/**
 * Textes d'une rubrique pour une langue.
 *
 * description et methodText contiennent du HTML deja assaini a l'ecriture
 * (06-securite §2) : la lecture ne fait plus qu'afficher.
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
        /**
         * Bande basse de la page rubrique (02-front-public §5). Nullable et en
         * fin de liste : le champ est facultatif et n'existait pas au lot 1.
         */
        public readonly ?string $methodText = null,
    ) {
    }
}
