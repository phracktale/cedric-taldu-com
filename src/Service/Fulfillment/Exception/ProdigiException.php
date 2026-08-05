<?php

declare(strict_types=1);

namespace App\Service\Fulfillment\Exception;

use RuntimeException;

/**
 * Échec d'un appel à l'API Prodigi (réseau, réponse non valide, statut d'erreur).
 *
 * Le message technique va au journal ; il n'atteint jamais un acheteur. Une
 * soumission qui échoue ne remet jamais en cause un paiement encaissé — le
 * service de fulfillment avale cette exception et la journalise.
 */
final class ProdigiException extends RuntimeException
{
}
