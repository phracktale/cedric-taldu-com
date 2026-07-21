<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use App\Domain\Admin\Role;
use App\Service\Auth\PasswordHasher;

/**
 * Compte d'administration de test.
 *
 * L'empreinte Argon2id du mot de passe par defaut est calculee UNE FOIS pour
 * tout le processus. Aux parametres de la spec, un hachage coute 130 ms : en
 * recalculer un par test ajouterait une minute a la suite pour produire
 * exactement la meme chaine.
 *
 * Un test qui a besoin d'un autre mot de passe le demande explicitement, et
 * paie alors son hachage.
 */
final class UserFactory extends Factory
{
    public const MOT_DE_PASSE = 'atelier-encre-papier-2026';

    private static ?string $empreintePartagee = null;

    private ?string $email = null;
    private string $displayName = 'Cédric Taldu';
    private Role $role = Role::Admin;
    private ?string $password = null;
    private ?string $totpSecret = null;
    private int $failedAttempts = 0;
    private ?string $lockedUntil = null;

    public function withEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function named(string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function asEditor(): self
    {
        $this->role = Role::Editor;

        return $this;
    }

    /**
     * Mot de passe distinct du defaut. Coute un hachage Argon2id complet.
     */
    public function withPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function withTotpSecret(string $secret): self
    {
        $this->totpSecret = $secret;

        return $this;
    }

    /**
     * @param string|null $lockedUntil date SQL, ou null pour un compte ouvert
     */
    public function locked(int $failedAttempts, ?string $lockedUntil): self
    {
        $this->failedAttempts = $failedAttempts;
        $this->lockedUntil = $lockedUntil;

        return $this;
    }

    public function create(): int
    {
        $n = self::next();

        $this->insert(
            'INSERT INTO users
                (email, password_hash, display_name, role, totp_secret,
                 failed_attempts, locked_until, created_at, updated_at)
             VALUES (:email, :hash, :nom, :role, :totp, :echecs, :verrou, NOW(), NOW())',
            [
                'email' => $this->email ?? ('artiste' . $n . '@example.test'),
                'hash' => $this->hash(),
                'nom' => $this->displayName,
                'role' => $this->role->value,
                'totp' => $this->totpSecret,
                'echecs' => $this->failedAttempts,
                'verrou' => $this->lockedUntil,
            ],
        );

        return $this->lastInsertId();
    }

    private function hash(): string
    {
        $hasher = new PasswordHasher();

        if ($this->password !== null) {
            return $hasher->hash($this->password);
        }

        return self::$empreintePartagee ??= $hasher->hash(self::MOT_DE_PASSE);
    }
}
