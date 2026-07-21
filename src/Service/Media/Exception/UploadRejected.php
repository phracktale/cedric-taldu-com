<?php

declare(strict_types=1);

namespace App\Service\Media\Exception;

use App\Service\Media\UploadRejection;
use RuntimeException;

/**
 * Un televersement a ete refuse.
 *
 * src/CLAUDE.md : « Les exceptions du domaine sont typees et ne portent jamais
 * de message destine a l'affichage direct : le controleur les traduit. » Le
 * message technique sert au journal ; c'est `reason()->message()` qui atteint
 * l'artiste.
 */
final class UploadRejected extends RuntimeException
{
    private function __construct(private readonly UploadRejection $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(UploadRejection $reason, string $detail = ''): self
    {
        return new self(
            $reason,
            'Téléversement refusé : ' . $reason->value . ($detail === '' ? '' : ' — ' . $detail),
        );
    }

    public function reason(): UploadRejection
    {
        return $this->reason;
    }
}
