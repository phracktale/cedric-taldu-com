<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Niveaux de journalisation employes par le site.
 *
 * Volontairement reduits : on n'a pas de production de logs a grande echelle,
 * et un niveau de plus est un niveau que personne ne relit.
 */
enum LogLevel: string
{
    case Debug = 'DEBUG';
    case Info = 'INFO';
    /** Evenement de securite : rejet CSRF, limite de debit, upload refuse. */
    case Warning = 'WARNING';
    case Error = 'ERROR';
}
