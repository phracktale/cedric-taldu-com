<?php

declare(strict_types=1);

namespace APress\Core\Http\Controller;

use APress\Core\Admin\AdminUser;
use APress\Core\Auth\AdminSession;
use APress\Core\Auth\Authenticator;
use APress\Core\Auth\BackupCodes;
use APress\Core\Auth\Throttle;
use APress\Core\Auth\Totp;
use APress\Core\RedirectResponse;
use APress\Core\Repository\UserRepository;
use APress\Core\Request;
use APress\Core\Response;
use APress\Core\Ui\AdminShell;

/**
 * Connexion au back-office, en deux étapes si le compte a un second facteur.
 * Même parcours que cedrictaldu (dont il est issu) :
 *
 *  - limitation de débit par adresse avant toute vérification ;
 *  - un message d'échec UNIQUE, quelle que soit la cause (adresse inconnue,
 *    mot de passe faux, compte verrouillé) : rien à énumérer ;
 *  - second facteur : le verrou du compte est RELU à chaque code, ce qui
 *    borne le nombre d'essais toutes sessions intermédiaires confondues ;
 *  - code TOTP d'abord, code de secours ensuite (consommé, journalisé).
 */
final class AuthController
{
    private const LIMIT = 10;
    private const WINDOW = 900;
    private const FAILURE = 'Adresse ou mot de passe incorrect.';

    public function __construct(
        private readonly AdminShell $shell,
        private readonly AdminSession $session,
        private readonly Authenticator $authenticator,
        private readonly UserRepository $users,
        private readonly Throttle $throttle,
        private readonly Totp $totp,
        private readonly BackupCodes $backupCodes,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        return $this->loginPage($request);
    }

    public function login(Request $request): Response
    {
        if (!$this->throttle->allow('auth.login', $request->clientIp, self::LIMIT, self::WINDOW)) {
            return $this->loginPage($request, 'Trop de tentatives. Réessayez dans quelques minutes.', 429);
        }

        $email = trim($request->input('email') ?? '');
        $password = $request->input('mot_de_passe') ?? '';

        if ($email === '' || $password === '' || strlen($email) > 190 || strlen($password) > 200) {
            return $this->loginPage($request, self::FAILURE, 422);
        }

        $user = $this->authenticator->attempt($email, $password, $request);
        if ($user === null) {
            return $this->loginPage($request, self::FAILURE, 422);
        }

        if ($user->hasTwoFactor()) {
            $this->session->beginTwoFactor($user->id);

            return RedirectResponse::to($request->basePath . '/admin/connexion/2fa');
        }

        return $this->open($user, $request);
    }

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
        $user = $pending === null ? null : $this->users->findById($pending);

        if ($user === null || !$user->hasTwoFactor()) {
            $this->session->clearPending();

            return RedirectResponse::to($request->basePath . '/admin/connexion');
        }

        if ($user->isLocked($this->shell->now())) {
            return $this->twoFactorPage($request, 'Code incorrect.', 422);
        }

        $code = trim($request->input('code') ?? '');
        $counter = $this->totp->accept((string) $user->totpSecret, $code, $this->shell->now(), $user->totpLastCounter);

        if ($counter !== null) {
            $this->users->updateTotpCounter($user->id, $counter);

            return $this->open($user, $request);
        }

        if ($this->users->consumeBackupCode($user->id, $this->backupCodes->hash($code), $this->shell->now())) {
            return $this->open($user, $request, 'auth.backup_code_used');
        }

        $this->authenticator->registerFailure($user, $request, 'auth.two_factor_failed');

        return $this->twoFactorPage($request, 'Code incorrect.', 422);
    }

    public function logout(Request $request): Response
    {
        $user = $this->session->authenticate($request);
        if ($user !== null) {
            $this->shell->audit()->record($user->id, 'auth.logout', $request);
        }

        $this->session->destroy();

        return RedirectResponse::to($request->basePath . '/admin/connexion');
    }

    private function open(AdminUser $user, Request $request, string $action = 'auth.login'): Response
    {
        $this->authenticator->registerSuccess($user, $request, $action);
        $this->session->start($user, $request);

        return RedirectResponse::to($request->basePath . '/admin');
    }

    private function loginPage(Request $request, ?string $error = null, int $status = 200): Response
    {
        return $this->shell->page($request, 'admin/auth/login', [
            'titre' => 'Connexion',
            'erreur' => $error,
            'email' => $request->input('email') ?? '',
        ], $status);
    }

    private function twoFactorPage(Request $request, ?string $error = null, int $status = 200): Response
    {
        return $this->shell->page($request, 'admin/auth/two-factor', ['titre' => 'Vérification', 'erreur' => $error], $status);
    }
}
