<?php

declare(strict_types=1);

namespace App\Service\Spam;

use App\Core\ClockInterface;
use App\Repository\RateLimitRepository;

/**
 * Limitation de debit a fenetre glissante.
 *
 * 06-securite §6.3. La cle est SHA-256(portee + identifiant + poivre) :
 * « l'IP en clair n'est jamais stockee ». Le poivre vit dans .env — sans lui,
 * quiconque connait l'adresse ciblee recalculerait la cle et lirait le compteur
 * dans un dump vole.
 *
 * Une seule methode publique de decision, `allow()`, qui compte ET tranche. Une
 * API en deux temps — `hit()` puis `count()` — se prete a l'oubli du second
 * appel, et un oubli de limitation ne se voit pas : tout continue de marcher.
 */
final class RateLimiter
{
    /**
     * Largeur d'une tranche, en secondes.
     *
     * Une minute : assez fin pour que la fenetre glisse de facon percue comme
     * continue, assez large pour qu'une heure de trafic tienne en soixante
     * lignes par cle plutot qu'en trois mille six cents.
     */
    public const SLICE_SECONDS = 60;

    public function __construct(
        private readonly RateLimitRepository $counters,
        private readonly ClockInterface $clock,
        private readonly string $pepper,
    ) {
    }

    /**
     * Enregistre une tentative et dit si elle reste dans les clous.
     *
     * @param string $scope      portee, par exemple « auth.login »
     * @param string $identifier adresse IP, jamais stockee telle quelle
     * @param int    $limit      nombre de tentatives tolerees dans la fenetre
     * @param int    $window     largeur de la fenetre, en secondes
     */
    public function allow(string $scope, string $identifier, int $limit, int $window): bool
    {
        $now = $this->clock->now();

        // La tranche est alignee sur un multiple de SLICE_SECONDS : deux
        // requetes de la meme minute incrementent la MEME ligne, sinon la table
        // porterait une ligne par requete et la cle primaire ne servirait a rien.
        $sliceStart = $now->setTimestamp(
            intdiv($now->getTimestamp(), self::SLICE_SECONDS) * self::SLICE_SECONDS
        );

        $total = $this->counters->hitAndCount(
            $this->key($scope, $identifier),
            $sliceStart,
            $now->modify('-' . $window . ' seconds'),
        );

        return $total <= $limit;
    }

    /**
     * Cle du compartiment. Publique pour que le journal de securite puisse
     * designer un compartiment sans jamais nommer l'adresse.
     */
    public function key(string $scope, string $identifier): string
    {
        return hash('sha256', $scope . "\0" . $identifier . "\0" . $this->pepper);
    }
}
