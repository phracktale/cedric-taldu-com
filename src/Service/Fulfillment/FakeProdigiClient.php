<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use Throwable;

/**
 * Double déterministe du client Prodigi, pour les tests et pour un environnement
 * sans clé (le site reste fonctionnel, la soumission est simplement inerte).
 *
 * Il enregistre les charges soumises (pour les vérifier), peut renvoyer un
 * résultat choisi, et peut simuler une panne — de quoi éprouver l'invariant
 * « une soumission qui échoue ne perd jamais le paiement ».
 */
final class FakeProdigiClient implements ProdigiClientInterface
{
    /** @var list<array<string, mixed>> charges reçues, dans l'ordre */
    public array $orders = [];

    /** @var list<array<string, mixed>> demandes de devis reçues, dans l'ordre */
    public array $quotes = [];

    private ?Throwable $failure = null;
    private string $nextId = 'ord_fake';
    private string $nextStage = 'InProgress';

    private ?Throwable $quoteFailure = null;
    private int $nextQuoteCents = 495;
    private string $nextQuoteCurrency = 'EUR';

    public function respondWith(string $id, string $stage = 'InProgress'): void
    {
        $this->nextId = $id;
        $this->nextStage = $stage;
    }

    public function failWith(Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function respondQuoteWith(int $shippingCents, string $currency = 'EUR'): void
    {
        $this->nextQuoteCents = $shippingCents;
        $this->nextQuoteCurrency = $currency;
    }

    public function failQuoteWith(Throwable $failure): void
    {
        $this->quoteFailure = $failure;
    }

    public function createOrder(array $payload): ProdigiOrderResult
    {
        $this->orders[] = $payload;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new ProdigiOrderResult($this->nextId, $this->nextStage);
    }

    public function quote(array $payload): ProdigiQuoteResult
    {
        $this->quotes[] = $payload;

        if ($this->quoteFailure !== null) {
            throw $this->quoteFailure;
        }

        return new ProdigiQuoteResult($this->nextQuoteCents, $this->nextQuoteCurrency);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastOrder(): ?array
    {
        return $this->orders === [] ? null : $this->orders[array_key_last($this->orders)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastQuote(): ?array
    {
        return $this->quotes === [] ? null : $this->quotes[array_key_last($this->quotes)];
    }
}
