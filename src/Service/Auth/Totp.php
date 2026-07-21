<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Core\RandomInterface;
use DateTimeImmutable;

/**
 * Mot de passe a usage unique fonde sur le temps — RFC 6238.
 *
 * Ecrit et non installe : aucune dependance de production n'est autorisee
 * au-dela de stripe/stripe-php et phpmailer/phpmailer (CLAUDE.md §5). L'algorithme
 * tient en une cinquantaine de lignes avec hash_hmac, et sa correction se prouve
 * par les vecteurs officiels de l'annexe B, rejoues par TotpTest.
 *
 * Parametres imposes par ce que savent lire les applications d'authentification
 * grand public : HMAC-SHA1, pas de 30 secondes, six chiffres. Ce ne sont pas des
 * choix de securite mais d'interoperabilite ; la robustesse du second facteur
 * tient au secret de 160 bits, pas a la fonction de hachage.
 */
final class Totp
{
    /** Duree d'une fenetre, en secondes. */
    public const PERIOD = 30;

    /** Longueur du code affiche. */
    public const DIGITS = 6;

    /** Taille du secret, en octets — 160 bits, comme le recommande la RFC 4226. */
    public const SECRET_BYTES = 20;

    /**
     * Tolerance, en pas de part et d'autre de la fenetre courante.
     *
     * Un seul pas : l'artiste lit le code a la 29e seconde et le saisit a la
     * 31e. Sans cette tolerance, la 2FA rejette un code juste et devient
     * insupportable ; avec deux pas ou plus, la fenetre de rejeu s'allonge sans
     * contrepartie d'ergonomie.
     */
    public const WINDOW = 1;

    public function generateSecret(RandomInterface $random): string
    {
        $bytes = hex2bin($random->hex(self::SECRET_BYTES));

        // Un generateur qui ne rendrait pas de l'hexadecimal est un defaut de
        // programmation, pas une entree a valider : on ne le rattrape pas en
        // silence, on laisse le secret vide plutot que faible.
        return $bytes === false ? '' : rtrim(Base32::encode($bytes), '=');
    }

    /**
     * Code de la fenetre qui contient cet instant.
     */
    public function code(string $secret, DateTimeImmutable $at): string
    {
        return $this->codeForCounter($secret, intdiv($at->getTimestamp(), self::PERIOD));
    }

    /**
     * La comparaison est faite en temps constant sur CHAQUE fenetre, et la
     * boucle n'est jamais interrompue par un `return` anticipe : sortir des la
     * premiere correspondance laisserait mesurer laquelle des trois fenetres a
     * repondu, donc la derive d'horloge du serveur.
     */
    public function verify(string $secret, string $code, DateTimeImmutable $now, int $window = self::WINDOW): bool
    {
        if (preg_match('/^[0-9]{' . self::DIGITS . '}$/D', $code) !== 1) {
            return false;
        }

        if (Base32::decode($secret) === null) {
            return false;
        }

        $counter = intdiv($now->getTimestamp(), self::PERIOD);
        $valid = false;

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->codeForCounter($secret, $counter + $offset), $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /**
     * Verifie un code ET rend le numero de fenetre accepte, ou null.
     *
     * RFC 6238 §5.2 : « The verifier MUST NOT accept the second attempt of the
     * OTP after the successful validation has been issued for the first OTP. »
     * Un code reste valide trente secondes, et la tolerance d'un pas porte cette
     * fenetre a une minute et demie : sans memoire, un code observe par-dessus
     * l'epaule resservirait pendant tout ce temps.
     *
     * C'est le NUMERO DE FENETRE qui est conserve, jamais le code : aucun secret
     * supplementaire ne se retrouve en base, et la comparaison se resume a un
     * « strictement superieur au dernier accepte ».
     *
     * @param  int|null $lastCounter derniere fenetre acceptee pour ce compte
     * @return int|null fenetre a memoriser, ou null si le code est refuse
     */
    public function accept(string $secret, string $code, DateTimeImmutable $now, ?int $lastCounter): ?int
    {
        if (preg_match('/^[0-9]{' . self::DIGITS . '}$/D', $code) !== 1 || Base32::decode($secret) === null) {
            return null;
        }

        $current = intdiv($now->getTimestamp(), self::PERIOD);
        $accepted = null;

        // La boucle va du plus ancien au plus recent et ne s'interrompt pas :
        // sortir des la premiere correspondance laisserait mesurer quelle
        // fenetre a repondu, donc la derive d'horloge du serveur.
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $counter = $current + $offset;

            if ($lastCounter !== null && $counter <= $lastCounter) {
                continue;
            }

            if (hash_equals($this->codeForCounter($secret, $counter), $code)) {
                $accepted = $counter;
            }
        }

        return $accepted;
    }

    /**
     * URI otpauth:// a encoder en QR code.
     *
     * Tous les composants sont encodes : le libelle finit dans une URL, et un
     * « / » ou une espace non encodes cassent le lien pour l'application
     * d'authentification.
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        $parameters = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $parameters;
    }

    /**
     * HOTP, RFC 4226 §5.3 : HMAC-SHA1 du compteur sur huit octets en gros
     * boutiste, troncature dynamique guidee par le dernier quartet, puis modulo.
     */
    private function codeForCounter(string $secret, int $counter): string
    {
        $key = Base32::decode($secret);

        if ($key === null) {
            return str_repeat('0', self::DIGITS);
        }

        $digest = hash_hmac('sha1', pack('J', $counter), $key, true);

        // Le dernier quartet designe l'octet de depart : c'est ce qui empeche un
        // attaquant de savoir a l'avance quelle partie du condensat est publiee.
        $offset = ord($digest[strlen($digest) - 1]) & 0x0F;

        $binary = ((ord($digest[$offset]) & 0x7F) << 24)
            | ((ord($digest[$offset + 1]) & 0xFF) << 16)
            | ((ord($digest[$offset + 2]) & 0xFF) << 8)
            | (ord($digest[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($binary % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }
}
