<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

/**
 * Résultat d'une création de commande Prodigi : identifiant et étape courante.
 *
 * `stage` reprend `order.status.stage` de l'API (InProgress | Complete |
 * Cancelled). L'identifiant sert ensuite à retrouver la commande et à
 * rapprocher les callbacks.
 */
final class ProdigiOrderResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $stage,
    ) {
    }
}
