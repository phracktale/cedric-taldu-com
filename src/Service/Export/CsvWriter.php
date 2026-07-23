<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * Ecriture CSV, avec neutralisation des formules.
 *
 * L'INJECTION CSV est reelle : un tableur execute tout champ commencant par
 * « = », « + », « - », « @ » (ou une tabulation, un retour chariot). Un nom de
 * client « =cmd|calc!A1 » deviendrait une commande a l'ouverture du fichier.
 * Ces champs viennent en partie du client (nom, e-mail) : chaque valeur est
 * donc prefixee d'une apostrophe quand elle commence par un caractere
 * dangereux, et toutes sont echappees selon RFC 4180.
 */
final class CsvWriter
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<string>                 $headers
     * @param list<array<string, string>>  $rows    chaque ligne indexee par en-tete
     */
    public static function build(array $headers, array $rows): string
    {
        $lines = [self::line($headers)];

        foreach ($rows as $row) {
            $ordered = [];

            foreach ($headers as $header) {
                $ordered[] = $row[$header] ?? '';
            }

            $lines[] = self::line($ordered);
        }

        // BOM UTF-8 : sans lui, Excel lit les accents comme du latin-1. CRLF
        // entre les lignes, comme l'attend RFC 4180.
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<string> $cells
     */
    private static function line(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells));
    }

    private static function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::DANGEROUS_PREFIXES, true)) {
            // L'apostrophe force le tableur a traiter la valeur comme du texte.
            $value = "'" . $value;
        }

        // RFC 4180 : une valeur contenant guillemet, virgule ou saut de ligne
        // est entouree de guillemets, et les guillemets internes sont doubles.
        if (preg_match('/[",\r\n]/', $value) === 1) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
