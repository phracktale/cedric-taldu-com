<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

use App\Core\ClockInterface;

/**
 * Invalidation par suppression : sans fichier, Apache renvoie la requête à
 * PHP, qui rend la page à jour. L'état passe « périmé » pour la barre, et
 * l'instant d'invalidation empêche une génération déjà en cours de publier
 * des pages rendues avant la modification.
 */
final class FileInvalidator implements Invalidator
{
    public function __construct(
        private readonly StaticDirectory $directory,
        private readonly GenerationStore $store,
        private readonly ClockInterface $clock,
    ) {
    }

    public function invalidate(): void
    {
        $this->directory->clear();

        $now = $this->clock->now();
        $this->store->save($this->store->load()->withStale($now->format('Y-m-d\TH:i:s.vP')), $now);
    }
}
