<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Core\Csrf;
use App\Core\SessionInterface;

/**
 * Session de l'espace client (revue du 2026-09-24).
 *
 * Distincte de la session d'administration (AdminSession) : une clef à part,
 * l'adresse e-mail validée par le lien. À la connexion, l'identifiant de
 * session et le jeton CSRF sont renouvelés (fixation de session).
 */
final class CustomerSession
{
    private const KEY = 'customer.email';

    public function __construct(
        private readonly SessionInterface $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function login(string $email): void
    {
        $this->session->regenerateId();
        $this->session->set(self::KEY, $email);
        $this->csrf->regenerate();
    }

    public function email(): ?string
    {
        $email = $this->session->get(self::KEY);

        return $email === null || $email === '' ? null : $email;
    }

    public function logout(): void
    {
        $this->session->remove(self::KEY);
        $this->session->regenerateId();
        $this->csrf->regenerate();
    }
}
