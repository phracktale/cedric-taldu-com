<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\MissingReferenceTranslation;

/**
 * Ensemble des traductions d'un contenu, avec le repli de 05-i18n-seo §3.
 *
 * Le contenu francais est obligatoire, le contenu anglais facultatif. Une page
 * /en/... dont la traduction manque est servie AVEC LE TEXTE FRANCAIS, precedee
 * d'une mention discrete, et son hreflang n'est pas emis. Le repli ne produit
 * jamais un 404 ni une page vide.
 *
 * @template T of object
 */
final class Translations
{
    /** @var array<string, T> */
    private readonly array $byLocale;

    /**
     * @param array<string, T> $byLocale indexe par code de langue
     */
    public function __construct(array $byLocale)
    {
        $this->byLocale = $byLocale;
    }

    /**
     * Traduction a employer pour cette langue, avec repli sur le francais.
     *
     * @return T
     */
    public function for(Locale $locale): object
    {
        return $this->byLocale[$locale->value]
            ?? $this->byLocale[Locale::reference()->value]
            ?? throw MissingReferenceTranslation::forLocale($locale);
    }

    /**
     * Vrai si la traduction existe REELLEMENT dans cette langue.
     *
     * C'est ce drapeau qui decide d'emettre le hreflang de la paire et
     * d'afficher « This text is only available in French ».
     */
    public function isAvailableIn(Locale $locale): bool
    {
        return isset($this->byLocale[$locale->value]);
    }

    /**
     * @return list<Locale>
     */
    public function availableLocales(): array
    {
        $locales = [];

        foreach (Locale::cases() as $locale) {
            if ($this->isAvailableIn($locale)) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }
}
