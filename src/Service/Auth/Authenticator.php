<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Core\ClockInterface;
use App\Core\Request;
use App\Domain\Admin\AdminUser;
use App\Repository\UserRepository;

/**
 * Verification des identifiants d'administration.
 *
 * 04-back-office §1 et 06-securite §4. Trois regles se jouent ici, et deux
 * d'entre elles ne se voient pas dans le resultat rendu :
 *
 *  1. Un compte inconnu declenche une comparaison FACTICE. Sans elle, la
 *     reponse revient en deux millisecondes au lieu de cent trente, et le
 *     formulaire devient un enumerateur d'adresses.
 *  2. Un compte verrouille est traite exactement comme un mot de passe faux, y
 *     compris dans la duree : le verrou est verifie APRES la comparaison, pas
 *     avant.
 *  3. L'empreinte est reencodee si les parametres du hachage ont change.
 *
 * Le service ne connait ni la session ni la reponse HTTP : il dit oui ou non, et
 * trace. C'est le controleur qui decide de la suite.
 */
final class Authenticator
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return AdminUser|null le compte, ou null si l'authentification echoue
     */
    public function attempt(string $email, string $password, Request $request): ?AdminUser
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Le travail est fait quand meme : c'est toute la raison d'etre de
            // cette methode.
            $this->hasher->verifyDummy();
            $this->audit->record(null, 'auth.login_failed', $request);

            return null;
        }

        $passwordMatches = $this->hasher->verify($password, $user->passwordHash);

        // Le verrou est consulte APRES la comparaison : le tester avant ferait
        // repondre un compte verrouille plus vite qu'un compte ouvert, ce qui
        // revelerait son existence.
        if (!$passwordMatches || $user->isLocked($this->clock->now())) {
            $this->registerFailure($user, $request);

            return null;
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        return $user;
    }

    /**
     * Echec imputable a un compte connu : second facteur refuse, par exemple.
     *
     * Compter les codes TOTP rates est indispensable : sans cela, le second
     * facteur serait un espace de six chiffres a essayer sans limite.
     */
    public function registerFailure(AdminUser $user, Request $request, string $action = 'auth.login_failed'): void
    {
        $this->users->save($user->withFailure($this->clock->now()));
        $this->audit->record($user->id, $action, $request);
    }

    /**
     * Connexion pleinement reussie : compteur remis a zero, visite datee.
     */
    public function registerSuccess(AdminUser $user, Request $request, string $action = 'auth.login'): void
    {
        $this->users->save($user->withSuccess($this->clock->now()));
        $this->audit->record($user->id, $action, $request);
    }
}
