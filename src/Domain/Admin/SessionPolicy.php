<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use DateTimeImmutable;

/**
 * Regles de duree de vie et d'appartenance d'une session d'administration.
 *
 * 06-securite §4 : « Inactivite 30 min, duree absolue 12 h, empreinte faible
 * (user-agent + reseau /24) verifiee pour detecter un vol de session grossier —
 * sans bloquer un changement d'IP legitime. »
 *
 * Classe pure : elle ne lit ni la session, ni l'horloge, ni la requete. Tout lui
 * est donne, ce qui permet d'eprouver une session de douze heures sans en
 * attendre une seule.
 *
 * L'empreinte est volontairement FAIBLE. Elle attrape le cookie recopie ailleurs,
 * pas un attaquant qui prend soin d'imiter le navigateur et le reseau de sa
 * cible. Une empreinte forte — adresse exacte, en-tetes complets — fermerait la
 * session de l'artiste a chaque changement de reseau, ce qui conduirait a la
 * desactiver, donc a ne plus rien attraper du tout.
 */
final class SessionPolicy
{
    /** Inactivite tolerée, en secondes : trente minutes. */
    public const IDLE_SECONDS = 1800;

    /** Duree de vie absolue, en secondes : douze heures. */
    public const ABSOLUTE_SECONDS = 43200;

    public function __construct(private readonly string $pepper)
    {
    }

    /**
     * Empreinte faible du couple navigateur + reseau.
     *
     * Hachee et poivree : elle est stockee en session et journalisee, elle ne
     * doit donc etre ni une donnee personnelle en clair (06-securite §9), ni
     * reconstituable par quiconque connait l'adresse et le navigateur de la
     * cible.
     */
    public function fingerprint(string $userAgent, string $ip): string
    {
        return hash('sha256', $this->pepper . "\0" . $userAgent . "\0" . self::network($ip));
    }

    /**
     * L'expiration absolue prime sur l'inactivite : c'est la borne qu'aucune
     * activite ne peut repousser, et la nommer correctement importe pour le
     * journal.
     *
     * L'empreinte est verifiee en dernier, en temps constant : comparer par
     * `===` deux valeurs dont l'une est fournie par l'attaquant laisserait
     * mesurer la longueur du prefixe correct.
     */
    public function verdict(
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $lastSeenAt,
        string $fingerprint,
        DateTimeImmutable $now,
        string $currentFingerprint,
    ): SessionStatus {
        if ($now->getTimestamp() - $issuedAt->getTimestamp() >= self::ABSOLUTE_SECONDS) {
            return SessionStatus::AbsoluteTimeout;
        }

        if ($now->getTimestamp() - $lastSeenAt->getTimestamp() >= self::IDLE_SECONDS) {
            return SessionStatus::IdleTimeout;
        }

        if (!hash_equals($fingerprint, $currentFingerprint)) {
            return SessionStatus::FingerprintMismatch;
        }

        return SessionStatus::Valid;
    }

    /**
     * Reseau auquel appartient l'adresse.
     *
     * IPv4 : les trois premiers octets, soit le /24 de la spec. IPv6 : les
     * quatre premiers groupes, soit le /64 — le prefixe stable attribue a un
     * abonne. Sans ce traitement, chaque adresse temporaire d'un client IPv6
     * fermerait la session.
     *
     * Une valeur illisible est reprise telle quelle : elle reste comparable a
     * elle-meme sans devenir un joker qui vaudrait pour toutes les autres.
     */
    private static function network(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $ip);

            return implode('.', array_slice($octets, 0, 3)) . '.0/24';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // inet_pton rend la forme binaire canonique : « 2001:db8::1 » et
            // « 2001:0db8:0000:0000:0000:0000:0000:0001 » donnent le meme
            // prefixe, alors qu'un decoupage textuel les separerait.
            $packed = inet_pton($ip);

            if (is_string($packed)) {
                return bin2hex(substr($packed, 0, 8)) . '/64';
            }
        }

        return 'brut:' . $ip;
    }
}
