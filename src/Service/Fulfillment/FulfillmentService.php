<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Repository\FulfillmentRepository;
use App\Repository\PersistedOrder;
use App\Service\I18n\UrlGenerator;
use DateTimeImmutable;
use Throwable;

/**
 * Soumet à Prodigi les lignes REPRODUCTION d'une commande payée.
 *
 * Appelé APRÈS le commit du webhook de paiement (jamais dedans) : une panne de
 * Prodigi ne doit pas annuler un encaissement. Toute erreur est avalée et
 * journalisée ; la commande reste payée, sans identifiant Prodigi, et l'artiste
 * peut relancer depuis le back-office.
 *
 * Idempotent : une commande déjà soumise (prodigi_order_id présent) est ignorée,
 * ce qui rend les rejeux de webhook inoffensifs.
 *
 * Seules les lignes reproduction dont la variante porte un SKU Prodigi ET dont
 * l'œuvre porte un fichier d'impression sont envoyées. Les autres sont
 * journalisées et laissées à un traitement manuel.
 */
final class FulfillmentService
{
    /** Mode d'expédition Prodigi par défaut. */
    private const SHIPPING_METHOD = 'Standard';

    public function __construct(
        private readonly ProdigiClientInterface $client,
        private readonly ProdigiConfig $config,
        private readonly FulfillmentRepository $fulfillment,
        private readonly PrintAssetUrl $printUrls,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $logger,
        /** Secret du callback Prodigi, glissé dans l'URL de rappel de chaque commande. */
        private readonly string $callbackSecret,
    ) {
    }

    public function submit(PersistedOrder $order, DateTimeImmutable $now): void
    {
        if (!$this->config->isConfigured()) {
            $this->logger->log(LogLevel::Info, 'Prodigi non configuré : soumission ignorée', [
                'reference' => $order->reference,
            ]);

            return;
        }

        if ($this->fulfillment->alreadySubmitted($order->id)) {
            return;
        }

        // Les reproductions sont imprimées et expédiées par Prodigi : elles
        // exigent une adresse. Sans elle (retrait), rien à soumettre.
        if ($order->shippingAddress === null) {
            return;
        }

        $items = $this->items($order);

        if ($items === []) {
            return;
        }

        $address = $order->shippingAddress;
        $payload = [
            'merchantReference' => $order->reference,
            'shippingMethod' => self::SHIPPING_METHOD,
            'callbackUrl' => $this->url->absolute('prodigi.webhook', ['secret' => $this->callbackSecret]),
            'recipient' => [
                'name' => $order->customerName,
                'email' => $order->customerEmail,
                'address' => [
                    'line1' => $address->line1,
                    'line2' => $address->line2,
                    'townOrCity' => $address->city,
                    'postalOrZipCode' => $address->postalCode,
                    'countryCode' => $address->country,
                ],
            ],
            'items' => $items,
        ];

        try {
            $result = $this->client->createOrder($payload);
            $this->fulfillment->markSubmitted($order->id, $result->id, $result->stage, $now);
        } catch (Throwable $exception) {
            // Jamais de remontée : le paiement est déjà encaissé et validé.
            $this->logger->log(LogLevel::Error, 'Soumission Prodigi échouée', [
                'reference' => $order->reference,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(PersistedOrder $order): array
    {
        $items = [];

        foreach ($this->fulfillment->reproductionLinesFor($order->id) as $line) {
            if ($line['sku'] === '' || $line['printAssetPath'] === null) {
                $this->logger->log(LogLevel::Warning, 'Ligne reproduction non soumise à Prodigi', [
                    'reference' => $order->reference,
                    'artwork' => $line['artworkId'],
                    'motif' => $line['sku'] === '' ? 'sku_prodigi_absent' : 'fichier_impression_absent',
                ]);

                continue;
            }

            $assetUrl = $this->url->absolute(
                'print.asset',
                ['token' => $this->printUrls->token($line['artworkId'])],
            );

            $items[] = [
                'sku' => $line['sku'],
                'copies' => $line['copies'],
                'sizing' => $line['sizing'],
                'assets' => [['printArea' => 'default', 'url' => $assetUrl]],
            ];
        }

        return $items;
    }
}
