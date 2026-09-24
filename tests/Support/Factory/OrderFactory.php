<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

/**
 * Commande de test, avec une ligne d'original (espace client, revue du 2026-09-24).
 */
final class OrderFactory extends Factory
{
    private string $reference = 'CT-2026-0001';
    private string $status = 'paid';
    private string $email = 'camille@example.com';
    private ?string $paymentIntent = 'pi_test_123';
    private string $createdAt = '2026-09-20 10:00:00';

    public function reference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function status(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function forEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function createdAt(string $instant): self
    {
        $this->createdAt = $instant;

        return $this;
    }

    public function create(): int
    {
        $this->insert(
            'INSERT INTO orders
                (reference, status, locale, customer_email, customer_name, shipping_method, shipping_address,
                 subtotal_cents, shipping_cents, total_cents, access_token, stripe_payment_intent_id,
                 paid_at, created_at, updated_at)
             VALUES (:ref, :status, :locale, :email, :nom, :method, :address,
                 45000, 900, 45900, :token, :intent, :paid, :created, :created2)',
            [
                'ref' => $this->reference,
                'status' => $this->status,
                'locale' => 'fr',
                'email' => $this->email,
                'nom' => 'Camille Dupont',
                'method' => 'shipping',
                'address' => json_encode([
                    'line1' => '12 rue des Trois-Cailloux', 'line2' => null,
                    'postal_code' => '80000', 'city' => 'Amiens', 'country' => 'FR',
                ], JSON_THROW_ON_ERROR),
                'token' => bin2hex(random_bytes(32)),
                'intent' => $this->paymentIntent,
                'paid' => in_array($this->status, ['paid', 'shipped', 'refunded'], true) ? $this->createdAt : null,
                'created' => $this->createdAt,
                'created2' => $this->createdAt,
            ],
        );
        $id = $this->lastInsertId();

        $this->insert(
            'INSERT INTO order_items
                (order_id, kind, label, qty, unit_price_cents, total_cents, vat_category, vat_rate_bps,
                 ht_cents, vat_cents)
             VALUES (:id, :kind, :label, 1, 45000, 45000, :cat, 0, 45000, 0)',
            ['id' => $id, 'kind' => 'original', 'label' => 'Articulation — 2026', 'cat' => 'original_artwork'],
        );

        return $id;
    }
}
