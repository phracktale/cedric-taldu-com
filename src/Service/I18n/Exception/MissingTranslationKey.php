<?php

declare(strict_types=1);

namespace App\Service\I18n\Exception;

use App\Domain\Locale;
use RuntimeException;

/**
 * Clé de traduction absente du catalogue.
 *
 * Levée en développement (05-i18n-seo §4) : une clé manquante est un défaut de
 * programmation, pas une donnée. En production, le {@see \App\Service\I18n\Translator}
 * ne lève pas — il retombe sur la clé et journalise — pour ne jamais casser une page.
 */
final class MissingTranslationKey extends RuntimeException
{
    public static function forKey(string $key, Locale $locale): self
    {
        return new self(sprintf('Clé de traduction « %s » absente pour la langue « %s ».', $key, $locale->value));
    }
}
