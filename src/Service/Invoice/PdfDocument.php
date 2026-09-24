<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/**
 * Générateur PDF minimal (revue du 2026-09-24).
 *
 * Aucune dépendance nouvelle n'étant autorisée (CLAUDE.md, règle 5), la facture
 * est écrite ici : PDF 1.4, pages A4, texte non compressé en Helvetica et
 * Helvetica-Bold (polices standard, rien à embarquer), encodage WinAnsi pour
 * les accents du français. Suffisant pour un document textuel ; pas d'image.
 *
 * Coordonnées en points depuis le coin inférieur gauche (A4 = 595 × 842).
 */
final class PdfDocument
{
    public const WIDTH = 595;
    public const HEIGHT = 842;

    /** @var list<string> flux de contenu des pages terminées */
    private array $pages = [];

    /** Flux de contenu de la page en cours. */
    private string $current = '';

    public function text(float $x, float $y, float $size, string $text, bool $bold = false): self
    {
        $this->current .= sprintf(
            "BT /%s %s Tf %s %s Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            self::number($size),
            self::number($x),
            self::number($y),
            self::escape($text),
        );

        return $this;
    }

    public function line(float $x1, float $y1, float $x2, float $y2): self
    {
        $this->current .= sprintf(
            "0.5 w %s %s m %s %s l S\n",
            self::number($x1),
            self::number($y1),
            self::number($x2),
            self::number($y2),
        );

        return $this;
    }

    public function newPage(): self
    {
        $this->pages[] = $this->current;
        $this->current = '';

        return $this;
    }

    /**
     * Largeur approximative d'un texte (Helvetica ≈ 0,5 em par caractère), pour
     * aligner les montants à droite.
     */
    public static function approximateWidth(string $text, float $size): float
    {
        return mb_strlen($text) * $size * 0.5;
    }

    public function output(): string
    {
        // Objets : 1 catalogue, 2 arbre des pages, 3-4 polices, puis pour chaque
        // page son objet et son flux de contenu.
        $pages = [...$this->pages, $this->current];
        $count = count($pages);
        $kids = [];
        for ($i = 0; $i < $count; $i++) {
            $kids[] = (5 + 2 * $i) . ' 0 R';
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        foreach ($pages as $i => $content) {
            $page = 5 + 2 * $i;
            $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . ']'
                . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ($page + 1) . ' 0 R >>';
            $objects[$page + 1] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /**
     * Texte UTF-8 → chaîne PDF WinAnsi, caractères spéciaux échappés. Un
     * caractère absent de Windows-1252 devient « ? » plutôt que de casser le flux.
     */
    private static function escape(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
        $encoded = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
