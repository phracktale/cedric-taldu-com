<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use DateTimeImmutable;

/**
 * Fourchette de réception estimée, en jours OUVRÉS.
 *
 * Les CGV (art. 6) annoncent une expédition « sous 7 jours ouvrables » ; on y
 * ajoute le transit du transporteur pour proposer à l'acheteur une fenêtre de
 * réception plausible. C'est une ESTIMATION affichée, sans valeur contractuelle
 * — l'autorité reste au serveur pour tout ce qui touche à l'argent.
 *
 * Le calcul saute les samedis et dimanches : une commande du vendredi ne doit
 * pas hériter de deux jours de retard fictifs.
 */
final class DeliveryEstimate
{
    /** Borne basse : expédition (7 j ouvrés, CGV art. 6) + un jour de transit. */
    private const MIN_WORKING_DAYS = 7;

    /** Borne haute : expédition + transit du transporteur. */
    private const MAX_WORKING_DAYS = 11;

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [borne basse, borne haute]
     */
    public static function range(DateTimeImmutable $from): array
    {
        return [
            self::addWorkingDays($from, self::MIN_WORKING_DAYS),
            self::addWorkingDays($from, self::MAX_WORKING_DAYS),
        ];
    }

    public static function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable
    {
        $date = $from;
        $ajoutes = 0;

        while ($ajoutes < $days) {
            $date = $date->modify('+1 day');

            // format('N') : 1 (lundi) à 7 (dimanche). On ne compte que 1 à 5.
            if ((int) $date->format('N') <= 5) {
                $ajoutes++;
            }
        }

        return $date;
    }
}
