<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\ClockInterface;
use App\Core\Csrf;
use App\Core\Response;
use Tests\Support\Doubles\FrozenClock;

/**
 * Base des tests fonctionnels du back-office.
 *
 * Ajoute a FunctionalTestCase deux choses dont chaque parcours d'administration
 * a besoin :
 *
 *  1. Une horloge gelee CABLEE DANS LE CONTENEUR. Le verrouillage de compte,
 *     l'expiration de session et la fenetre de limitation de debit se mesurent
 *     en minutes : sans horloge maitrisee, ces tests seraient soit instables,
 *     soit interminables.
 *  2. Un POST qui porte le jeton CSRF. Toutes les routes du back-office sont
 *     protegees ; ecrire le jeton a la main dans chaque test noierait la regle
 *     metier sous la mecanique.
 */
abstract class AdminTestCase extends FunctionalTestCase
{
    protected FrozenClock $horloge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->horloge = new FrozenClock();
        $this->withService(ClockInterface::class, fn (): ClockInterface => $this->horloge);
    }

    /**
     * Jeton CSRF de la session courante.
     *
     * Pose s'il n'existe pas encore : un test qui poste sans avoir affiche le
     * formulaire au prealable reste ecrit sur la regle qu'il eprouve.
     */
    protected function jetonCsrf(): string
    {
        $jeton = $this->session->get(Csrf::SESSION_KEY);

        if ($jeton === null || $jeton === '') {
            $jeton = str_repeat('a', 64);
            $this->session->set(Csrf::SESSION_KEY, $jeton);
        }

        return $jeton;
    }

    /**
     * @param array<string, string> $post
     * @param array<string, mixed>  $server
     */
    protected function postAvecJeton(string $uri, array $post = [], array $server = []): Response
    {
        return $this->requete('POST', $uri, server: $server, post: [
            Csrf::FIELD => $this->jetonCsrf(),
            ...$post,
        ]);
    }

    /**
     * Parcours de connexion complet, jusqu'a la session ouverte.
     *
     * @param array<string, mixed> $server
     */
    protected function seConnecter(
        string $email,
        string $motDePasse = Factory\UserFactory::MOT_DE_PASSE,
        array $server = [],
    ): Response {
        return $this->postAvecJeton(
            '/cedric-taldu/admin/connexion',
            ['email' => $email, 'mot_de_passe' => $motDePasse],
            $server,
        );
    }
}
