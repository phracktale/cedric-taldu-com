<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use DateTimeImmutable;

/**
 * Compte d'administration.
 *
 * Entite immuable et sans I/O : le comptage des echecs et l'expiration du verrou
 * se testent sans base et sans attendre quinze minutes. Le depot ne fait que
 * persister le resultat de `withFailure()` ou de `withSuccess()`.
 *
 * L'empreinte du mot de passe est portee ici mais n'est JAMAIS comparee ici :
 * la verification appartient au service qui detient l'algorithme, et le domaine
 * n'a pas a connaitre Argon2id.
 */
final class AdminUser
{
    /** 04-back-office §1 : cinq echecs verrouillent le compte. */
    public const MAX_FAILED_ATTEMPTS = 5;

    /** Duree du verrouillage, en secondes : un quart d'heure. */
    public const LOCK_SECONDS = 900;

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly string $displayName,
        public readonly Role $role,
        public readonly ?string $totpSecret,
        public readonly int $failedAttempts,
        public readonly ?DateTimeImmutable $lockedUntil,
        public readonly ?DateTimeImmutable $lastLoginAt,
    ) {
    }

    // ------------------------------------------------------------ verrouillage

    /**
     * Le verrou expire A l'echeance, pas apres : `lockedUntil` est la premiere
     * seconde ou la connexion redevient possible.
     */
    public function isLocked(DateTimeImmutable $now): bool
    {
        return $this->lockedUntil !== null && $now < $this->lockedUntil;
    }

    /**
     * Echec de connexion supplementaire.
     *
     * Le compteur n'est pas remis a zero par l'expiration du verrou : seule une
     * connexion reussie l'efface. Sans cela, un attaquant patient disposerait de
     * cinq essais tous les quarts d'heure indefiniment — une temporisation, pas
     * un verrouillage.
     */
    public function withFailure(DateTimeImmutable $now): self
    {
        $attempts = $this->failedAttempts + 1;

        return $this->with(
            failedAttempts: $attempts,
            lockedUntil: $attempts >= self::MAX_FAILED_ATTEMPTS
                ? $now->modify('+' . self::LOCK_SECONDS . ' seconds')
                : $this->lockedUntil,
        );
    }

    public function withSuccess(DateTimeImmutable $now): self
    {
        return $this->with(failedAttempts: 0, lockedUntil: null, lastLoginAt: $now);
    }

    // -------------------------------------------------------------------- 2FA

    /**
     * 04-back-office §1 : la 2FA est optionnelle, mais des qu'un secret existe
     * elle devient obligatoire pour ce compte — sinon l'activer n'apporterait
     * rien. Une chaine vide en base ne vaut pas un secret : elle reclamerait un
     * code que personne ne peut produire.
     */
    public function hasTwoFactor(): bool
    {
        return $this->totpSecret !== null && $this->totpSecret !== '';
    }

    // ------------------------------------------------------------------ droits

    /**
     * @param 'catalog'|'orders'|'settings'|'users' $capability
     */
    public function can(string $capability): bool
    {
        return match ($capability) {
            'catalog' => $this->role->canManageCatalog(),
            'orders' => $this->role->canManageOrders(),
            'settings' => $this->role->canManageSettings(),
            'users' => $this->role->canManageUsers(),
        };
    }

    // ----------------------------------------------------------------- interne

    /**
     * Copie avec modification. Les champs non cites sont repris tels quels ;
     * `lockedUntil` et `lastLoginAt` etant nullables, ils passent par un
     * sentinelle `false` pour distinguer « inchange » de « remis a NULL ».
     */
    private function with(
        ?int $failedAttempts = null,
        DateTimeImmutable|false|null $lockedUntil = false,
        DateTimeImmutable|false|null $lastLoginAt = false,
    ): self {
        return new self(
            id: $this->id,
            email: $this->email,
            passwordHash: $this->passwordHash,
            displayName: $this->displayName,
            role: $this->role,
            totpSecret: $this->totpSecret,
            failedAttempts: $failedAttempts ?? $this->failedAttempts,
            lockedUntil: $lockedUntil === false ? $this->lockedUntil : $lockedUntil,
            lastLoginAt: $lastLoginAt === false ? $this->lastLoginAt : $lastLoginAt,
        );
    }
}
