<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\Locale;
use App\Domain\Order\Address;
use App\Domain\Order\SellerIdentity;
use App\Repository\PersistedOrder;

/**
 * Facture PDF d'une commande (revue du 2026-09-24).
 *
 * Le numéro de facture est la référence de la commande (CT-AAAA-NNNN), unique
 * et séquentielle. La date est celle du paiement. La mention de TVA vient de la
 * commande elle-même (VatMode), jamais d'un texte recopié ici.
 */
final class InvoiceRenderer
{
    private const LEFT = 50;
    private const RIGHT = 545;
    private const BOTTOM = 70;

    public function render(PersistedOrder $order, SellerIdentity $seller): string
    {
        $fr = $order->locale === Locale::Fr;
        $pdf = new PdfDocument();
        $y = 790.0;

        // En-tête : vendeur à gauche, titre à droite.
        $pdf->text(self::LEFT, $y, 14, $seller->name, bold: true);
        $titre = ($fr ? 'Facture n° ' : 'Invoice no. ') . $order->reference;
        $this->right($pdf, $y, 14, $titre, true);
        $y -= 16;
        foreach ($seller->addressLines() as $ligne) {
            $pdf->text(self::LEFT, $y, 9, $ligne);
            $y -= 12;
        }
        if ($seller->siret !== '') {
            $pdf->text(self::LEFT, $y, 9, 'SIRET ' . $seller->siret);
            $y -= 12;
        }
        if ($seller->extra !== '') {
            $pdf->text(self::LEFT, $y, 9, $seller->extra);
            $y -= 12;
        }
        if ($seller->email !== '') {
            $pdf->text(self::LEFT, $y, 9, $seller->email);
            $y -= 12;
        }

        $date = $order->paidAt ?? $order->createdAt;
        $this->right($pdf, 772, 9, ($fr ? 'Date : ' : 'Date: ') . ($date?->format('d/m/Y') ?? ''));

        // Client.
        $y -= 18;
        $pdf->text(self::LEFT, $y, 10, $fr ? 'Facturé à' : 'Billed to', bold: true);
        $y -= 14;
        $adresse = $order->billingAddress ?? $order->shippingAddress;
        foreach ([$order->customerName, $order->customerEmail, ...self::addressLines($adresse)] as $ligne) {
            $pdf->text(self::LEFT, $y, 9, $ligne);
            $y -= 12;
        }

        // Lignes.
        $y -= 16;
        $pdf->text(self::LEFT, $y, 9, $fr ? 'Désignation' : 'Description', bold: true);
        $pdf->text(360, $y, 9, $fr ? 'Qté' : 'Qty', bold: true);
        $pdf->text(400, $y, 9, $fr ? 'Prix unitaire' : 'Unit price', bold: true);
        $this->right($pdf, $y, 9, 'Total', true);
        $y -= 6;
        $pdf->line(self::LEFT, $y, self::RIGHT, $y);
        $y -= 14;

        foreach ($order->lines as $ligne) {
            if ($y < self::BOTTOM + 80) {
                $pdf->newPage();
                $y = 790.0;
            }

            $pdf->text(self::LEFT, $y, 9, mb_substr($ligne->label, 0, 60));
            $pdf->text(360, $y, 9, (string) $ligne->quantity);
            $pdf->text(400, $y, 9, self::money($ligne->unitPrice->cents, $fr));
            $this->right($pdf, $y, 9, self::money($ligne->total->cents, $fr));
            $y -= 14;
        }

        $pdf->line(self::LEFT, $y + 6, self::RIGHT, $y + 6);
        $y -= 8;

        // Totaux.
        foreach ([
            [$fr ? 'Sous-total' : 'Subtotal', $order->subtotal->cents, false],
            [$fr ? 'Frais de livraison' : 'Delivery', $order->shipping->cents, false],
        ] as [$libelle, $montant, $gras]) {
            $pdf->text(360, $y, 9, $libelle, bold: $gras);
            $this->right($pdf, $y, 9, self::money($montant, $fr));
            $y -= 14;
        }

        if ($order->vat->cents > 0) {
            $pdf->text(360, $y, 9, $fr ? 'dont TVA' : 'incl. VAT');
            $this->right($pdf, $y, 9, self::money($order->vat->cents, $fr));
            $y -= 14;
        }

        $pdf->text(360, $y, 11, $fr ? 'Total TTC' : 'Total', bold: true);
        $this->right($pdf, $y, 11, self::money($order->total->cents, $fr), true);
        $y -= 24;

        if ($order->legalMention() !== null) {
            $pdf->text(self::LEFT, $y, 9, (string) $order->legalMention());
            $y -= 14;
        }

        // Paiement.
        $paiement = $fr ? 'Payée par carte bancaire (Stripe)' : 'Paid by card (Stripe)';
        if ($order->paymentReference !== null) {
            $paiement .= ($fr ? ' — référence ' : ' — reference ') . $order->paymentReference;
        }
        $pdf->text(self::LEFT, $y, 9, $paiement);

        return $pdf->output();
    }

    private function right(PdfDocument $pdf, float $y, float $size, string $text, bool $bold = false): void
    {
        $pdf->text(self::RIGHT - PdfDocument::approximateWidth($text, $size), $y, $size, $text, bold: $bold);
    }

    /**
     * @return list<string>
     */
    private static function addressLines(?Address $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_values(array_filter([
            $address->line1,
            $address->line2 ?? '',
            trim($address->postalCode . ' ' . $address->city),
            $address->country,
        ], static fn (string $l): bool => $l !== ''));
    }

    /**
     * Montant en euros, sans passer par des flottants.
     */
    private static function money(int $cents, bool $fr): string
    {
        $euros = intdiv(abs($cents), 100);
        $reste = str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
        $signe = $cents < 0 ? '-' : '';

        return $fr
            ? $signe . number_format($euros, 0, ',', ' ') . ',' . $reste . ' €'
            : $signe . '€' . number_format($euros, 0, '.', ',') . '.' . $reste;
    }
}
