<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use App\Service\Fulfillment\Exception\ProdigiException;

/**
 * Contrat du client Prodigi.
 *
 * Une interface pour que le fulfillment se teste contre un double déterministe
 * (FakeProdigiClient) — aucun test ne touche le réseau (tests/CLAUDE.md). Le
 * client réel (curl) n'est exercé qu'en recette contre le sandbox.
 */
interface ProdigiClientInterface
{
    /**
     * Crée une commande d'impression.
     *
     * @param array<string, mixed> $payload corps conforme à POST /v4.0/orders
     *
     * @throws ProdigiException en cas d'échec réseau, de réponse invalide ou de statut d'erreur
     */
    public function createOrder(array $payload): ProdigiOrderResult;

    /**
     * Demande un devis d'expédition.
     *
     * @param array<string, mixed> $payload corps conforme à POST /v4.0/quotes
     *
     * @throws ProdigiException en cas d'échec réseau, de réponse invalide ou de statut d'erreur
     */
    public function quote(array $payload): ProdigiQuoteResult;
}
