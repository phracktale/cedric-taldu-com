<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;
use App\Domain\Slug;

/**
 * Textes d'une page éditoriale pour une langue.
 *
 * `body` est du HTML déjà assaini à l'écriture (06-securite §2) : la lecture ne
 * fait plus qu'afficher.
 */
final class PageTranslation
{
    public function __construct(
        public readonly Locale $locale,
        public readonly Slug $slug,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        /** Document JSON de blocs (format editor-core), ou null si page « body ». */
        public readonly ?string $blocksJson = null,
    ) {
    }

    /**
     * Blocs éditoriaux de la page, ou [] si elle suit encore son HTML `body`.
     *
     * @return list<Block>
     */
    public function blocks(): array
    {
        return Block::listFromJson($this->blocksJson);
    }

    /** La page est-elle composée par blocs (plutôt que par son HTML historique) ? */
    public function hasBlocks(): bool
    {
        return $this->blocks() !== [];
    }
}
