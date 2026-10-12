<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Classement de la page « Toutes les œuvres » (demande du 2026-10-12), au
 * choix de l'artiste. Réglage `works.sort`.
 *
 * L'ordre libre est le défaut : son rang (artworks.works_position) part de
 * l'ordre affiché jusque-là, donc rien ne change tant que rien n'est choisi.
 */
enum WorksSort: string
{
    /** Rang propre à la page, réglé par glisser-déposer en back-office. */
    case Manual = 'libre';

    /** Titre dans la langue affichée, sans accents ni casse, numéros suivis. */
    case Alphabetical = 'alphabetique';

    /** Année de l'œuvre, la plus récente d'abord ; sans année, à la fin. */
    case Recent = 'recentes';

    public const SETTING = 'works.sort';

    /**
     * @param array<mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $mode = $stored['mode'] ?? null;

        return is_string($mode) ? (self::tryFrom($mode) ?? self::Manual) : self::Manual;
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Ordre libre, par glisser-déposer dans la liste',
            self::Alphabetical => 'Alphabétique',
            self::Recent => 'Les plus récentes d’abord (année de l’œuvre)',
        };
    }

    /**
     * @return array{mode: string}
     */
    public function toArray(): array
    {
        return ['mode' => $this->value];
    }
}
