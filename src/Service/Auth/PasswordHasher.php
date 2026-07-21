<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Hachage des mots de passe d'administration.
 *
 * 04-back-office §1 : Argon2id, 64 Mio de memoire, quatre passes, deux fils —
 * et reencodage a la connexion si les parametres ont change. Les parametres sont
 * ici et nulle part ailleurs : c'est la seule facon qu'un durcissement futur
 * s'applique a tous les comptes au fil de leurs connexions.
 */
final class PasswordHasher
{
    /**
     * Empreinte de reference pour la comparaison factice.
     *
     * Litterale et non calculee au demarrage : la calculer couterait 130 ms a
     * chaque requete, y compris sur les pages publiques. Le mot de passe qui l'a
     * produite n'existe pas et n'ouvre aucun compte — c'est du sable, pas un
     * secret.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=2$L1VubUMwWVpROVlYZ2JJMA'
        . '$0JTu3Pv4D6Pr9/bQJv21oupBt+UYya69JIEOeqbn7hQ';

    /**
     * @return array<string, int>
     */
    public static function options(): array
    {
        return [
            'memory_cost' => 65536,  // 64 Mio
            'time_cost' => 4,
            'threads' => 2,
        ];
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, self::options());
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /**
     * Une empreinte illisible ou aux parametres perimes doit etre remplacee.
     *
     * password_needs_rehash() rend deja `true` pour une valeur qui n'est pas une
     * empreinte : elle ne verifiera jamais rien, autant la reecrire a la
     * premiere connexion reussie.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::options());
    }

    /**
     * Comparaison factice, pour un compte qui n'existe pas.
     *
     * 04-back-office §1 : « duree de traitement constante (comparaison factice
     * si l'utilisateur est inconnu) ». Sans elle, une reponse en deux
     * millisecondes au lieu de cent trente revele qu'aucun compte ne porte cette
     * adresse, et le formulaire de connexion devient un enumerateur de comptes.
     */
    public function verifyDummy(): void
    {
        password_verify('mot de passe qui n’ouvre rien', self::DUMMY_HASH);
    }
}
