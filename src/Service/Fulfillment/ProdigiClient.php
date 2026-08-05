<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use App\Service\Fulfillment\Exception\ProdigiException;

/**
 * Client HTTP réel de l'API Prodigi (curl, sans dépendance Composer).
 *
 * Volontairement mince : il authentifie (en-tête X-API-Key), envoie le JSON,
 * lit l'identifiant et l'étape de la commande, et traduit toute anomalie en
 * ProdigiException. La vérification TLS s'appuie sur le magasin de certificats
 * du système (contrairement au SDK Stripe qui embarque le sien) : pas de bundle
 * à committer, pas d'« errno 77 ».
 *
 * Non couvert par les tests (aucun appel réseau en CI) : le comportement se
 * valide en recette contre le sandbox ; la logique testable vit dans
 * FulfillmentService, éprouvé avec FakeProdigiClient.
 */
final class ProdigiClient implements ProdigiClientInterface
{
    private const TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;

    public function __construct(private readonly ProdigiConfig $config)
    {
    }

    public function createOrder(array $payload): ProdigiOrderResult
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProdigiException('Charge Prodigi non sérialisable.');
        }

        $reference = is_string($payload['merchantReference'] ?? null) ? $payload['merchantReference'] : '';

        $handle = curl_init($this->config->baseUrl . '/v4.0/orders');

        if ($handle === false) {
            throw new ProdigiException('Initialisation curl impossible.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->config->apiKey,
                'Content-Type: application/json',
                // Idempotence : rejouer la même référence ne crée pas de doublon.
                'Idempotency-Key: ' . $reference,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new ProdigiException('Prodigi injoignable : ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new ProdigiException('Prodigi a répondu ' . $status . ' : ' . substr((string) $body, 0, 500));
        }

        return $this->parse((string) $body);
    }

    public function quote(array $payload): ProdigiQuoteResult
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProdigiException('Charge de devis Prodigi non sérialisable.');
        }

        $handle = curl_init($this->config->baseUrl . '/v4.0/quotes');

        if ($handle === false) {
            throw new ProdigiException('Initialisation curl impossible.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->config->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new ProdigiException('Prodigi injoignable : ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new ProdigiException('Prodigi a répondu ' . $status . ' : ' . substr((string) $body, 0, 500));
        }

        return $this->parseQuote((string) $body);
    }

    private function parseQuote(string $body): ProdigiQuoteResult
    {
        $data = json_decode($body, true);
        $quotes = is_array($data) && is_array($data['quotes'] ?? null) ? $data['quotes'] : [];
        $first = $quotes[0] ?? null;
        $shipping = is_array($first) && is_array($first['costSummary']['shipping'] ?? null)
            ? $first['costSummary']['shipping']
            : null;

        if ($shipping === null || !is_string($shipping['amount'] ?? null)) {
            throw new ProdigiException('Devis Prodigi inattendu.');
        }

        $currency = is_string($shipping['currency'] ?? null) ? $shipping['currency'] : '';

        return new ProdigiQuoteResult(self::centsFromAmount($shipping['amount']), $currency);
    }

    /**
     * Convertit un montant décimal Prodigi (« 4.95 ») en centimes, sans flottant.
     */
    private static function centsFromAmount(string $decimal): int
    {
        $parts = explode('.', trim($decimal), 2);
        $whole = (int) $parts[0];
        $fraction = (int) substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2);

        return $whole * 100 + $fraction;
    }

    private function parse(string $body): ProdigiOrderResult
    {
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['order']) || !is_array($data['order'])) {
            throw new ProdigiException('Réponse Prodigi inattendue.');
        }

        $order = $data['order'];
        $id = is_string($order['id'] ?? null) ? $order['id'] : '';

        if ($id === '') {
            throw new ProdigiException('Réponse Prodigi sans identifiant de commande.');
        }

        $status = $order['status'] ?? null;
        $stage = is_array($status) && is_string($status['stage'] ?? null) ? $status['stage'] : 'Unknown';

        return new ProdigiOrderResult($id, $stage);
    }
}
