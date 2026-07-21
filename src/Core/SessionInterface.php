<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session serveur.
 *
 * Derriere une interface pour que les tests fonctionnels traversent le Kernel
 * sans demarrer de session PHP reelle, et pour que le domaine n'ait jamais
 * connaissance de $_SESSION.
 */
interface SessionInterface
{
    public function id(): string;

    public function has(string $key): bool;

    public function get(string $key, ?string $default = null): ?string;

    public function set(string $key, string $value): void;

    public function remove(string $key): void;

    public function clear(): void;

    /**
     * Change l'identifiant en conservant les donnees.
     *
     * Appelee a chaque elevation de privilege (06-securite §4) : sans elle, un
     * identifiant fixe par un attaquant avant la connexion reste valable apres.
     */
    public function regenerateId(): void;
}
