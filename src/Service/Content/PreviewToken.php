<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Core\ClockInterface;

/**
 * Jeton d'apercu d'un contenu non publie.
 *
 * 04-back-office §5 : « Apercu avant publication via un lien signe a duree
 * limitee (?preview=<jeton>). » C'est ce qui permet a l'artiste de montrer une
 * fiche a quelqu'un avant de la publier, sans compte ni session.
 *
 * Le jeton est une SIGNATURE, pas un secret stocke : il n'y a donc aucune table
 * a purger, et un lien cesse de valoir par le seul ecoulement du temps. La
 * signature porte le type, l'identifiant ET l'echeance — sans l'identifiant, le
 * jeton d'une œuvre ouvrirait toutes les autres ; sans l'echeance, un lien
 * partage une fois resterait valable indefiniment.
 *
 * La cle est DERIVEE du poivre de .env plutot que d'etre le poivre lui-meme :
 * une fuite de jeton ne doit rien apprendre sur les empreintes d'IP ni sur les
 * codes de secours, qui emploient le meme secret d'origine.
 */
final class PreviewToken
{
    /** Duree de vie, en secondes : vingt-quatre heures. */
    public const LIFETIME = 86400;

    private readonly string $key;

    public function __construct(string $pepper, private readonly ClockInterface $clock)
    {
        $this->key = hash_hmac('sha256', 'preview', $pepper, true);
    }

    public function issue(string $type, int $id): string
    {
        $expiry = $this->clock->now()->getTimestamp() + self::LIFETIME;

        return dechex($expiry) . '-' . $this->sign($type, $id, $expiry);
    }

    /**
     * Comparaison en TEMPS CONSTANT : un « === » laisserait mesurer la longueur
     * du prefixe correct et reconstituer la signature octet par octet.
     */
    public function isValid(string $token, string $type, int $id): bool
    {
        $parts = explode('-', $token, 2);

        if (count($parts) !== 2 || !ctype_xdigit($parts[0])) {
            return false;
        }

        $expiry = (int) hexdec($parts[0]);

        if ($expiry <= $this->clock->now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->sign($type, $id, $expiry), $parts[1]);
    }

    private function sign(string $type, int $id, int $expiry): string
    {
        return hash_hmac('sha256', $type . "\0" . $id . "\0" . $expiry, $this->key);
    }
}
