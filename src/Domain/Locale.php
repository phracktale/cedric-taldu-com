<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\UnsupportedLocale;

/**
 * Langue servie par le site.
 *
 * 05-i18n-seo §1 : deux langues, fr et en. La langue est TOUJOURS dans l'URL,
 * et une meme URL sert toujours la meme langue.
 *
 * Le francais est la langue de reference : 01-modele-de-donnees, invariant 10,
 * impose que tout enregistrement traduisible possede au minimum sa ligne fr.
 * La ligne en est facultative et declenche le repli documente en §3.
 */
enum Locale: string
{
    case Fr = 'fr';
    case En = 'en';

    public static function fromString(string $code): self
    {
        return self::tryFrom(strtolower(trim($code)))
            ?? throw UnsupportedLocale::forCode($code);
    }

    /**
     * Langue dont le contenu est obligatoire, et vers laquelle on replie.
     */
    public static function reference(): self
    {
        return self::Fr;
    }

    public function isReference(): bool
    {
        return $this === self::reference();
    }

    /**
     * Valeur de l'attribut lang de la balise html.
     */
    public function htmlLang(): string
    {
        return $this->value;
    }

    /**
     * Nom de la langue DANS cette langue : un selecteur qui propose
     * « Anglais » est illisible pour celui qui en a besoin.
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::Fr => 'Français',
            self::En => 'English',
        };
    }
}
