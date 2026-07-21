<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;
use App\Domain\Slug;

/**
 * Textes d'une œuvre pour une langue.
 *
 * description et detail contiennent du HTML DEJA ASSAINI : l'assainissement a
 * lieu a l'ecriture, en back-office, et la lecture ne fait plus qu'afficher
 * (06-securite §2). C'est la raison pour laquelle ces champs ne repassent pas
 * par e() dans les gabarits.
 */
final class ArtworkTranslation
{
    public function __construct(
        public readonly Locale $locale,
        public readonly Slug $slug,
        public readonly string $title,
        public readonly ?string $eyebrow,
        public readonly ?string $description,
        public readonly ?string $detail,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
    ) {
    }
}
