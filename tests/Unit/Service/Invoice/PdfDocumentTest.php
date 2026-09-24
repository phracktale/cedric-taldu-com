<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Invoice;

use App\Service\Invoice\PdfDocument;
use PHPUnit\Framework\TestCase;

/**
 * Générateur PDF minimal (revue du 2026-09-24) : aucune dépendance nouvelle
 * n'étant autorisée, la facture est un PDF 1.4 texte, non compressé.
 */
final class PdfDocumentTest extends TestCase
{
    public function test_le_document_est_un_pdf_bien_forme(): void
    {
        $pdf = (new PdfDocument())->text(50, 800, 12, 'Facture')->output();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        // startxref pointe exactement sur la table xref.
        $this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m));
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4));
    }

    public function test_chaque_objet_est_a_l_adresse_annoncee_par_la_table(): void
    {
        $pdf = (new PdfDocument())->text(50, 800, 12, 'A')->newPage()->text(50, 800, 12, 'B')->output();

        preg_match('/xref\n0 (\d+)\n0000000000 65535 f \n((?:\d{10} 00000 n \n)+)/', $pdf, $table);
        $this->assertNotSame([], $table);
        $offsets = array_map('intval', array_map(static fn (string $l): string => substr($l, 0, 10), array_filter(explode("\n", $table[2]))));

        foreach ($offsets as $index => $offset) {
            $this->assertSame(($index + 1) . ' 0 obj', substr($pdf, $offset, strlen(($index + 1) . ' 0 obj')));
        }
        $this->assertStringContainsString('/Count 2', $pdf);
    }

    public function test_les_accents_sont_encodes_en_winansi_et_les_parentheses_echappees(): void
    {
        $pdf = (new PdfDocument())->text(50, 800, 12, 'Réf. (œuvre) \\ été')->output();

        $this->assertStringContainsString('(' . mb_convert_encoding('Réf. \\(œuvre\\) \\\\ été', 'Windows-1252', 'UTF-8') . ')', $pdf);
        $this->assertStringContainsString('/Encoding /WinAnsiEncoding', $pdf);
    }

    public function test_le_gras_utilise_la_police_grasse(): void
    {
        $pdf = (new PdfDocument())->text(50, 800, 12, 'Total', bold: true)->output();

        $this->assertStringContainsString('/F2 12 Tf', $pdf);
        $this->assertStringContainsString('/BaseFont /Helvetica-Bold', $pdf);
    }
}
