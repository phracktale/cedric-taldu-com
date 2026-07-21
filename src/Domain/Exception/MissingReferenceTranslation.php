<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Locale;
use DomainException;

/**
 * Un contenu n'a pas sa traduction dans la langue de reference.
 *
 * 01-modele-de-donnees, invariant 10 : chaque enregistrement traduisible
 * possede AU MINIMUM sa ligne fr. Si elle manque, la base est incoherente —
 * mieux vaut une erreur visible qu'une page a moitie vide en production.
 */
final class MissingReferenceTranslation extends DomainException
{
    public static function forLocale(Locale $requested): self
    {
        return new self(sprintf(
            'Contenu sans traduction « %s » ni repli « %s » : la ligne de référence est obligatoire.',
            $requested->value,
            Locale::reference()->value
        ));
    }
}
