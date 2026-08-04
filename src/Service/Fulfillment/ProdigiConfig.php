<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use RuntimeException;

/**
 * Résolution de la configuration Prodigi active.
 *
 * Sur le modèle de StripeConfig : le .env porte les deux clés — sandbox et
 * live — et `PRODIGI_ENV` désigne l'active. L'invariant de sécurité est le même,
 * et la conséquence plus concrète encore : en LIVE, chaque commande est
 * réellement imprimée et FACTURÉE À L'ARTISTE. `PRODIGI_ENV=live` sur une préprod
 * passerait de vraies commandes pendant une recette — c'est refusé au démarrage.
 * Le sandbox, lui, reste utilisable partout (aucune impression, aucun débit).
 *
 * Une clé active vide signifie « fulfillment non configuré » : le site démarre,
 * et c'est la tentative de soumission qui échouera — proprement, jamais en
 * perdant un paiement déjà encaissé.
 *
 * Les clés Prodigi n'ont pas de préfixe distinctif (contrairement à
 * `sk_test_`/`sk_live_`) : on ne peut donc pas vérifier qu'une clé correspond à
 * son mode. La garde « live seulement en prod » reste la protection essentielle.
 */
final class ProdigiConfig
{
    public const MODE_SANDBOX = 'sandbox';
    public const MODE_LIVE = 'live';

    private const BASE_URL = [
        self::MODE_SANDBOX => 'https://api.sandbox.prodigi.com',
        self::MODE_LIVE => 'https://api.prodigi.com',
    ];

    private function __construct(
        public readonly string $mode,
        public readonly string $apiKey,
        public readonly string $baseUrl,
    ) {
    }

    /**
     * @param array{sandboxKey: string, liveKey: string} $keys
     *
     * @throws RuntimeException si PRODIGI_ENV est invalide ou si le live est
     *         activé hors production.
     */
    public static function resolve(string $prodigiEnv, string $appEnv, array $keys): self
    {
        $mode = self::mode($prodigiEnv);

        // Les impressions de production ne s'activent qu'en production.
        if ($mode === self::MODE_LIVE && $appEnv !== 'prod') {
            throw new RuntimeException(
                'PRODIGI_ENV=live est interdit hors production : de vraies commandes '
                . 'seraient imprimées et facturées. Utilisez PRODIGI_ENV=sandbox.'
            );
        }

        $apiKey = $mode === self::MODE_LIVE ? $keys['liveKey'] : $keys['sandboxKey'];

        return new self($mode, $apiKey, self::BASE_URL[$mode]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    private static function mode(string $prodigiEnv): string
    {
        $mode = $prodigiEnv === '' ? self::MODE_SANDBOX : strtolower(trim($prodigiEnv));

        if (!in_array($mode, [self::MODE_SANDBOX, self::MODE_LIVE], true)) {
            throw new RuntimeException(sprintf(
                'PRODIGI_ENV invalide : « %s » (attendu « sandbox » ou « live »).',
                $prodigiEnv,
            ));
        }

        return $mode;
    }
}
