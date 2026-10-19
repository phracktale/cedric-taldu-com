<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\Exception\ValidationFailed;
use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Rule;
use App\Core\Validator;
use App\Domain\Admin\AdminUser;
use App\Domain\Admin\PasswordPolicy;
use App\Repository\UserRepository;
use App\Service\Auth\AdminSession;
use App\Service\Auth\Authenticator;
use App\Service\Auth\PasswordHasher;
use App\Service\Auth\PasswordReset;
use App\Service\I18n\UrlGenerator;
use App\Service\Mail\AccountMailer;
use App\Service\Spam\RateLimiter;
use App\Service\View\AdminChrome;
use Throwable;

/**
 * Changer et récupérer son mot de passe d'administration (demande du
 * 2026-10-19 : l'artiste dépendait d'une intervention en base).
 *
 *  - Connecté : le mot de passe actuel est redemandé — une session laissée
 *    ouverte ne suffit pas à le changer —, et un échec compte comme un échec
 *    de connexion.
 *  - Oublié : un lien à usage unique, envoyé à l'adresse du compte. La réponse
 *    est la même, que l'adresse ait un compte ou non (04-back-office §1).
 *
 * Le nouveau mot de passe passe par la même règle de saisie que la connexion
 * (Rule::text) : ce qui est enregistré est exactement ce qu'elle vérifiera.
 */
final class PasswordController
{
    /** Demandes de lien : 5 par quart d'heure et par IP. */
    private const REQUEST_LIMIT = 5;

    /** Saisies d'un nouveau mot de passe par lien : 10 par quart d'heure et par IP. */
    private const RESET_LIMIT = 10;

    private const WINDOW = 900;

    /** Assez large pour que la règle de longueur, et non le validateur, réponde. */
    private const INPUT_MAX = 1000;

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly AdminSession $session,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly Authenticator $authenticator,
        private readonly PasswordReset $resets,
        private readonly AccountMailer $mailer,
        private readonly RateLimiter $limiter,
        private readonly Validator $validator,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ------------------------------------------------------------- connecté

    public function showChange(Request $request): Response
    {
        $this->currentUser();

        return $this->changePage($request);
    }

    public function change(Request $request): Response
    {
        $user = $this->currentUser();

        try {
            $actuel = (string) $this->validator->validate($request->post, [
                'mot_de_passe_actuel' => Rule::text(PasswordPolicy::MAX_LENGTH),
            ])['mot_de_passe_actuel'];
        } catch (ValidationFailed) {
            $actuel = '';
        }

        if ($actuel === '' || !$this->hasher->verify($actuel, $user->passwordHash)) {
            $this->authenticator->registerFailure($user, $request, 'auth.password_change_failed');

            return $this->changePage($request, 'Le mot de passe actuel est incorrect.', 422);
        }

        $nouveau = $this->newPassword($request);
        if (is_string($nouveau['erreur'])) {
            return $this->changePage($request, $nouveau['erreur'], 422);
        }

        $this->apply($user->id, (string) $nouveau['valeur'], $request, 'auth.password_changed');

        // La session en cours repart de maintenant : ouverte avant le
        // changement, elle serait fermée comme les autres.
        $this->session->start($this->users->findById($user->id) ?? $user, $request);

        return $this->changePage($request, succes: 'Votre mot de passe a été modifié.');
    }

    // --------------------------------------------------------------- oublié

    public function showForgot(Request $request): Response
    {
        return $this->forgotPage($request);
    }

    public function requestReset(Request $request): Response
    {
        if (!$this->limiter->allow('auth.reset_request', $request->clientIp, self::REQUEST_LIMIT, self::WINDOW)) {
            return $this->forgotPage($request, erreur: 'Trop de demandes. Réessayez dans quelques minutes.', status: 429);
        }

        try {
            $email = (string) $this->validator->validate($request->post, ['email' => Rule::email()])['email'];
        } catch (ValidationFailed) {
            $email = '';
        }

        $user = $email === '' ? null : $this->users->findByEmail($email);

        if ($user !== null) {
            $lien = $this->url->absolute('admin.password.reset', ['token' => $this->resets->issue($user->id)]);

            try {
                $this->mailer->sendAdminPasswordReset($user->email, $lien);
            } catch (Throwable $exception) {
                $this->logger->log(LogLevel::Error, 'Lien de réinitialisation non envoyé', ['exception' => $exception::class]);
            }

            $this->chrome->audit()->record($user->id, 'auth.password_reset_requested', $request);
        }

        // Même réponse dans tous les cas : la page ne dit pas si l'adresse a un compte.
        return $this->forgotPage($request, envoye: true);
    }

    public function showReset(Request $request): Response
    {
        if ($this->resets->userIdFor($this->token($request)) === null) {
            return $this->invalidLink($request);
        }

        return $this->resetPage($request);
    }

    public function reset(Request $request): Response
    {
        if (!$this->limiter->allow('auth.reset', $request->clientIp, self::RESET_LIMIT, self::WINDOW)) {
            return $this->resetPage($request, 'Trop de tentatives. Réessayez dans quelques minutes.', 429);
        }

        $token = $this->token($request);
        if ($this->resets->userIdFor($token) === null) {
            return $this->invalidLink($request);
        }

        // Un mot de passe refusé laisse le lien valable : on corrige, on réessaie.
        $nouveau = $this->newPassword($request);
        if (is_string($nouveau['erreur'])) {
            return $this->resetPage($request, $nouveau['erreur'], 422);
        }

        $userId = $this->resets->consume($token);
        if ($userId === null) {
            return $this->invalidLink($request);
        }

        $this->apply($userId, (string) $nouveau['valeur'], $request, 'auth.password_reset');

        return RedirectResponse::to($request->basePath . '/admin/connexion?reinitialise=1', 303);
    }

    // -------------------------------------------------------------- interne

    /**
     * @return array{valeur: string|null, erreur: string|null}
     */
    private function newPassword(Request $request): array
    {
        try {
            $saisie = $this->validator->validate($request->post, [
                'nouveau' => Rule::text(self::INPUT_MAX),
                'confirmation' => Rule::text(self::INPUT_MAX),
            ]);
        } catch (ValidationFailed) {
            return ['valeur' => null, 'erreur' => PasswordPolicy::problem('', '')];
        }

        $nouveau = (string) $saisie['nouveau'];
        $probleme = PasswordPolicy::problem($nouveau, (string) $saisie['confirmation']);

        return $probleme === null
            ? ['valeur' => $nouveau, 'erreur' => null]
            : ['valeur' => null, 'erreur' => $probleme];
    }

    private function apply(int $userId, string $password, Request $request, string $action): void
    {
        $this->users->changePassword($userId, $this->hasher->hash($password), $this->chrome->now());
        $this->resets->revokeAll($userId);
        $this->chrome->audit()->record($userId, $action, $request);
    }

    private function token(Request $request): string
    {
        return (string) $request->attribute('token');
    }

    private function currentUser(): AdminUser
    {
        return $this->session->currentUser()
            ?? throw new NotFoundException('Aucun compte connecté.');
    }

    private function changePage(Request $request, ?string $erreur = null, int $status = 200, ?string $succes = null): Response
    {
        return $this->chrome->page($request, 'admin/compte/mot-de-passe', [
            'titre' => 'Mot de passe',
            'erreur' => $erreur,
            'succes' => $succes,
            'longueur' => PasswordPolicy::MIN_LENGTH,
        ], $status);
    }

    private function forgotPage(Request $request, bool $envoye = false, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/auth/mot-de-passe-oublie', [
            'titre' => 'Mot de passe oublié',
            'envoye' => $envoye,
            'erreur' => $erreur,
            'minutes' => PasswordReset::LIFETIME_MINUTES,
        ], $status);
    }

    private function resetPage(Request $request, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/auth/nouveau-mot-de-passe', [
            'titre' => 'Nouveau mot de passe',
            'valable' => true,
            'action' => $request->basePath . '/admin/mot-de-passe/' . $this->token($request),
            'erreur' => $erreur,
            'longueur' => PasswordPolicy::MIN_LENGTH,
        ], $status);
    }

    private function invalidLink(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/auth/nouveau-mot-de-passe', [
            'titre' => 'Lien expiré',
            'valable' => false,
        ], 400);
    }
}
