<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Core\ClockInterface;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\SessionInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Admin\SessionPolicy;
use App\Domain\Admin\SessionStatus;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Etat d'authentification du back-office, porte par la session.
 *
 * Rassemble en un seul endroit tout ce que 06-securite §4 impose : regeneration
 * de l'identifiant a l'elevation de privilege, bornes d'inactivite et de duree
 * absolue, empreinte faible, destruction cote serveur a la deconnexion.
 *
 * Un etat INTERMEDIAIRE existe entre le mot de passe et le second facteur. Il
 * porte des cles distinctes de celles de la session ouverte : c'est ce qui
 * garantit qu'aucune page d'administration ne s'ouvre a mi-parcours, meme si un
 * controleur oubliait de le verifier.
 */
final class AdminSession
{
    // Session pleinement ouverte.
    public const USER_ID = 'admin_user_id';
    public const ISSUED_AT = 'admin_issued_at';
    public const LAST_SEEN_AT = 'admin_last_seen_at';
    public const FINGERPRINT = 'admin_fingerprint';

    // Etat intermediaire : mot de passe valide, second facteur attendu.
    public const PENDING_USER_ID = 'admin_pending_user_id';
    public const PENDING_AT = 'admin_pending_at';

    // Enrolement 2FA : secret propose, non encore confirme par un code juste.
    public const PENDING_TOTP_SECRET = 'admin_pending_totp_secret';

    /**
     * Duree de vie de l'etat intermediaire, en secondes.
     *
     * Cinq minutes : un ecran de code laisse ouvert une heure sur un poste
     * partage est une session a moitie ouverte qui attend.
     */
    public const PENDING_SECONDS = 300;

    /** Utilisateur resolu pour CETTE requete, pour ne pas relire la base deux fois. */
    private ?AdminUser $current = null;

    private bool $resolved = false;

    private SessionStatus $lastStatus = SessionStatus::Valid;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly UserRepository $users,
        private readonly SessionPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly Csrf $csrf,
    ) {
    }

    // -------------------------------------------------------------- ouverture

    /**
     * Ouvre la session pour ce compte.
     *
     * L'identifiant de session ET le jeton CSRF sont regeneres : sans le
     * premier, un identifiant fixe par un attaquant avant la connexion reste
     * valable apres (06-securite §4) ; sans le second, un jeton obtenu en
     * anonyme resterait valable en session privilegiee (§3).
     */
    public function start(AdminUser $user, Request $request): void
    {
        $now = $this->clock->now();

        $this->session->regenerateId();
        $this->clearPending();

        $this->session->set(self::USER_ID, (string) $user->id);
        $this->session->set(self::ISSUED_AT, (string) $now->getTimestamp());
        $this->session->set(self::LAST_SEEN_AT, (string) $now->getTimestamp());
        $this->session->set(self::FINGERPRINT, $this->fingerprintOf($request));

        $this->csrf->regenerate();

        $this->current = $user;
        $this->resolved = true;
    }

    /**
     * 04-back-office §1 : « destruction de session cote serveur, pas seulement
     * suppression du cookie ».
     */
    public function destroy(): void
    {
        $this->session->clear();
        $this->session->regenerateId();
        $this->csrf->regenerate();

        $this->current = null;
        $this->resolved = true;
    }

    // ----------------------------------------------------------- verification

    /**
     * Compte authentifie pour cette requete, ou null.
     *
     * Le compte est RELU en base a chaque requete plutot que recopie en
     * session : un changement de role ou une suppression prend effet
     * immediatement, sans attendre la fin d'une session de douze heures.
     */
    public function authenticate(Request $request): ?AdminUser
    {
        if ($this->resolved) {
            return $this->current;
        }

        $this->resolved = true;
        $this->current = null;

        $id = $this->session->get(self::USER_ID);
        $issuedAt = $this->timestamp(self::ISSUED_AT);
        $lastSeenAt = $this->timestamp(self::LAST_SEEN_AT);
        $fingerprint = $this->session->get(self::FINGERPRINT);

        if ($id === null || $issuedAt === null || $lastSeenAt === null || $fingerprint === null) {
            return null;
        }

        $now = $this->clock->now();
        $this->lastStatus = $this->policy->verdict(
            issuedAt: $issuedAt,
            lastSeenAt: $lastSeenAt,
            fingerprint: $fingerprint,
            now: $now,
            currentFingerprint: $this->fingerprintOf($request),
        );

        if (!$this->lastStatus->isValid()) {
            $this->destroy();
            $this->resolved = true;

            return null;
        }

        $user = $this->users->findById((int) $id);

        if ($user === null) {
            $this->destroy();
            $this->resolved = true;

            return null;
        }

        // L'activite repousse l'inactivite, jamais la duree absolue.
        $this->session->set(self::LAST_SEEN_AT, (string) $now->getTimestamp());

        return $this->current = $user;
    }

    public function currentUser(): ?AdminUser
    {
        return $this->current;
    }

    /**
     * Motif de la derniere fermeture, pour le journal de securite. Jamais
     * affiche : le visiteur voit toujours le meme message.
     */
    public function lastStatus(): SessionStatus
    {
        return $this->lastStatus;
    }

    // ------------------------------------------------------ second facteur

    public function beginTwoFactor(int $userId): void
    {
        // L'identifiant est regenere DES le mot de passe valide : c'est deja une
        // elevation partielle, et l'attendre laisserait une fenetre de fixation.
        $this->session->regenerateId();

        $this->session->set(self::PENDING_USER_ID, (string) $userId);
        $this->session->set(self::PENDING_AT, (string) $this->clock->now()->getTimestamp());
    }

    /**
     * Compte en attente de second facteur, ou null si l'etat a expire.
     */
    public function pendingUserId(): ?int
    {
        $id = $this->session->get(self::PENDING_USER_ID);
        $since = $this->timestamp(self::PENDING_AT);

        if ($id === null || $since === null) {
            return null;
        }

        if ($this->clock->now()->getTimestamp() - $since->getTimestamp() >= self::PENDING_SECONDS) {
            $this->clearPending();

            return null;
        }

        return (int) $id;
    }

    public function clearPending(): void
    {
        $this->session->remove(self::PENDING_USER_ID);
        $this->session->remove(self::PENDING_AT);
    }

    // -------------------------------------------------------- enrolement 2FA

    public function proposeTotpSecret(string $secret): void
    {
        $this->session->set(self::PENDING_TOTP_SECRET, $secret);
    }

    public function proposedTotpSecret(): ?string
    {
        return $this->session->get(self::PENDING_TOTP_SECRET);
    }

    public function clearProposedTotpSecret(): void
    {
        $this->session->remove(self::PENDING_TOTP_SECRET);
    }

    // ------------------------------------------------------------- interne

    private function fingerprintOf(Request $request): string
    {
        return $this->policy->fingerprint($request->header('User-Agent') ?? '', $request->clientIp);
    }

    private function timestamp(string $key): ?DateTimeImmutable
    {
        $value = $this->session->get($key);

        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        return new DateTimeImmutable('@' . $value, new DateTimeZone('UTC'));
    }
}
