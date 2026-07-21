<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\ValidationFailed;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Rule;
use App\Core\Validator;
use App\Domain\Admin\AdminUser;
use App\Repository\UserRepository;
use App\Service\Auth\AdminSession;
use App\Service\Auth\Authenticator;
use App\Service\Auth\BackupCodes;
use App\Service\Auth\Totp;
use App\Service\Spam\RateLimiter;
use App\Service\View\AdminChrome;

/**
 * Connexion, second facteur et deconnexion.
 *
 * Un seul message d'echec pour TOUS les cas — compte inconnu, mot de passe faux,
 * compte verrouille (04-back-office §1). Un message plus precis, meme bien
 * intentionne, transforme le formulaire en enumerateur d'adresses ou confirme
 * qu'un verrouillage a porte.
 *
 * La limitation de debit est appliquee ICI plutot que par un middleware : la
 * limite de 06-securite §6.3 est propre a l'action (dix connexions par quart
 * d'heure, trois contacts par heure), et un middleware generique aurait de toute
 * facon eu besoin d'une table de limites par route.
 */
final class AuthController
{
    /** 06-securite §6.3 : dix tentatives par quart d'heure et par adresse. */
    private const LIMITE = 10;
    private const FENETRE = 900;

    private const MESSAGE_ECHEC = 'Identifiants incorrects, ou compte temporairement verrouillé.';

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly AdminSession $session,
        private readonly Authenticator $authenticator,
        private readonly UserRepository $users,
        private readonly Validator $validator,
        private readonly RateLimiter $limiter,
        private readonly Totp $totp,
        private readonly BackupCodes $backupCodes,
    ) {
    }

    // --------------------------------------------------------------- etape 1

    public function showLogin(Request $request): Response
    {
        return $this->loginPage($request);
    }

    public function login(Request $request): Response
    {
        if (!$this->limiter->allow('auth.login', $request->clientIp, self::LIMITE, self::FENETRE)) {
            return $this->loginPage($request, 'Trop de tentatives. Réessayez dans quelques minutes.', 429);
        }

        try {
            $saisie = $this->validator->validate($request->post, [
                'email' => Rule::email(),
                'mot_de_passe' => Rule::text(200),
            ]);
        } catch (ValidationFailed) {
            // Le detail des erreurs de format n'est pas restitue : « adresse
            // invalide » sur un champ et « obligatoire » sur l'autre en dirait
            // deja plus que le message unique.
            return $this->loginPage($request, self::MESSAGE_ECHEC, 422);
        }

        $user = $this->authenticator->attempt(
            (string) $saisie['email'],
            (string) $saisie['mot_de_passe'],
            $request,
        );

        if ($user === null) {
            return $this->loginPage($request, self::MESSAGE_ECHEC, 422);
        }

        if ($user->hasTwoFactor()) {
            $this->session->beginTwoFactor($user->id);

            return RedirectResponse::to($request->basePath . '/admin/connexion/2fa');
        }

        return $this->open($user, $request);
    }

    // --------------------------------------------------------------- etape 2

    public function showTwoFactor(Request $request): Response
    {
        if ($this->session->pendingUserId() === null) {
            return RedirectResponse::to($request->basePath . '/admin/connexion');
        }

        return $this->twoFactorPage($request);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        $pending = $this->session->pendingUserId();

        if ($pending === null) {
            return RedirectResponse::to($request->basePath . '/admin/connexion');
        }

        $user = $this->users->findById($pending);

        if ($user === null || !$user->hasTwoFactor()) {
            $this->session->clearPending();

            return RedirectResponse::to($request->basePath . '/admin/connexion');
        }

        // Le verrou est ECRIT par registerFailure() a chaque code faux — encore
        // faut-il le LIRE. Sans cette ligne, il ne fermait que l'etape du mot de
        // passe : l'etat intermediaire deja ouvert continuait d'accepter des
        // codes, et l'etape du mot de passe autorisant dix sessions
        // intermediaires par adresse AVANT le moindre echec, un attaquant en
        // ouvrait dix d'avance puis les martelait en parallele. Six chiffres et
        // trois fenetres acceptees font une chance sur trois cent mille par
        // essai : c'est le NOMBRE d'essais, et lui seul, qui tient le second
        // facteur.
        //
        // Le compte etant RELU a chaque tentative, ce controle vaut pour toutes
        // les sessions intermediaires a la fois : cinq echecs au total ferment
        // le compte, quelle que soit la session par laquelle ils arrivent. Une
        // limitation de debit supplementaire serait morte — elle mordrait a dix
        // essais, le verrou ferme a cinq.
        if ($user->isLocked($this->chrome->now())) {
            return $this->twoFactorPage($request, 'Code incorrect.', 422);
        }

        $code = trim($request->input('code') ?? '');

        // Le code TOTP d'abord, le code de secours ensuite : le second n'est
        // employe que lorsque le premier a echoue, donc rarement.
        $counter = $this->totp->accept(
            (string) $user->totpSecret,
            $code,
            $this->chrome->now(),
            $user->totpLastCounter,
        );

        if ($counter !== null) {
            $this->users->updateTotpCounter($user->id, $counter);

            return $this->open($user, $request);
        }

        if ($this->users->consumeBackupCode($user->id, $this->backupCodes->hash($code), $this->chrome->now())) {
            // Evenement anormal : l'artiste a perdu son telephone, ou quelqu'un
            // d'autre detient sa feuille de codes.
            return $this->open($user, $request, 'auth.backup_code_used');
        }

        $this->authenticator->registerFailure($user, $request, 'auth.two_factor_failed');

        return $this->twoFactorPage($request, 'Code incorrect.', 422);
    }

    // ---------------------------------------------------------- deconnexion

    public function logout(Request $request): Response
    {
        $user = $this->session->authenticate($request);

        if ($user !== null) {
            $this->chrome->audit()->record($user->id, 'auth.logout', $request);
        }

        $this->session->destroy();

        return RedirectResponse::to($request->basePath . '/admin/connexion');
    }

    // -------------------------------------------------------------- interne

    private function open(AdminUser $user, Request $request, string $action = 'auth.login'): Response
    {
        $this->authenticator->registerSuccess($user, $request, $action);
        $this->session->start($user, $request);

        return RedirectResponse::to($request->basePath . '/admin');
    }

    private function loginPage(Request $request, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page(
            $request,
            'admin/auth/connexion',
            ['titre' => 'Connexion', 'erreur' => $erreur, 'email' => $request->input('email') ?? ''],
            $status,
        );
    }

    private function twoFactorPage(Request $request, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page(
            $request,
            'admin/auth/deuxieme-facteur',
            ['titre' => 'Vérification', 'erreur' => $erreur],
            $status,
        );
    }
}
