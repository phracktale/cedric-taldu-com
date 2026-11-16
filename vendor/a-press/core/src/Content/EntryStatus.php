<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * État d'une entrée, tel que l'affiche la liste d'administration.
 */
enum EntryStatus: string
{
    /** Jamais publiée. */
    case Draft = 'draft';
    /** En ligne, sans modification en attente. */
    case Published = 'published';
    /** En ligne, avec un brouillon qui diffère (à publier). */
    case Changed = 'changed';
    /** Reçue du site (message, candidature) : ni brouillon ni publication. */
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'Publié',
            self::Changed => 'Modifications non publiées',
            self::Received => 'Reçu',
        };
    }
}
