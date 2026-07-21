<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Jeton anti-falsification de requete.
 *
 * 06-securite §3 : un jeton de 32 octets par session, compare par hash_equals,
 * obligatoire sur toutes les methodes non-GET, regenere a la connexion et a la
 * deconnexion. La seule exemption est le webhook Stripe, protege par signature.
 */
final class Csrf
{
    /** Cle de stockage en session. */
    public const SESSION_KEY = 'csrf_token';

    /** Nom du champ de formulaire et de l'en-tete equivalente. */
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';

    private const BYTES = 32;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly RandomInterface $random,
    ) {
    }

    public function token(): string
    {
        $existing = $this->session->get(self::SESSION_KEY);

        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        return $this->regenerate();
    }

    public function regenerate(): string
    {
        $token = $this->random->hex(self::BYTES);
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Comparaison en temps constant : un « === » laisserait mesurer la longueur
     * du prefixe correct et reconstituer le jeton octet par octet.
     */
    public function isValid(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        $expected = $this->session->get(self::SESSION_KEY);

        if ($expected === null || $expected === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }
}
