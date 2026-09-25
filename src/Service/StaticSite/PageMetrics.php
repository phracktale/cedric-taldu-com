<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

use App\Domain\EcoIndex\EcoIndex;

/**
 * Mesures EcoIndex d'une page : éléments du DOM, requêtes, octets transférés.
 */
final class PageMetrics
{
    public function __construct(
        public readonly int $dom,
        public readonly int $requests,
        public readonly int $bytes,
    ) {
    }

    public function kilobytes(): float
    {
        return $this->bytes / 1024;
    }

    public function ecoIndex(): EcoIndex
    {
        return EcoIndex::compute($this->dom, $this->requests, $this->kilobytes());
    }
}
