<?php

declare(strict_types=1);

namespace App\Service\Payment;

use RuntimeException;

/**
 * Résolution de la configuration Stripe active (06-securite §7).
 *
 * Le .env porte LES DEUX paires de clés — test et production — et `STRIPE_ENV`
 * désigne l'active. Cela évite de jongler avec un unique jeu de clés au moment
 * de basculer en production.
 *
 * L'invariant de sécurité est conservé, et même resserré : les clés de
 * PRODUCTION ne sont actives qu'en production. `STRIPE_ENV=prod` sur une préprod
 * encaisserait de vrais paiements pendant une recette — c'est refusé au
 * démarrage. À l'inverse, une clé de TEST reste utilisable partout, y compris en
 * production, pour une recette avant la bascule définitive.
 *
 * Une paire active vide signifie « paiement non configuré » : le site démarre
 * (portfolio compris), et c'est la tentative de paiement qui échouera.
 */
final class StripeConfig
{
    public const MODE_TEST = 'test';
    public const MODE_PROD = 'prod';

    private const PREFIX = [
        self::MODE_TEST => 'sk_test_',
        self::MODE_PROD => 'sk_live_',
    ];

    private function __construct(
        public readonly string $mode,
        public readonly string $secretKey,
        public readonly string $webhookSecret,
    ) {
    }

    /**
     * @param array{testKey: string, testWebhook: string, liveKey: string, liveWebhook: string} $keys
     *
     * @throws RuntimeException si STRIPE_ENV est invalide, si la production est
     *         activée hors production, ou si une clé ne correspond pas au mode.
     */
    public static function resolve(string $stripeEnv, string $appEnv, array $keys): self
    {
        $mode = self::mode($stripeEnv);

        // Les clés de production ne s'activent qu'en production.
        if ($mode === self::MODE_PROD && $appEnv !== 'prod') {
            throw new RuntimeException(
                'STRIPE_ENV=prod est interdit hors production : les clés de production '
                . 'encaisseraient de vrais paiements. Utilisez STRIPE_ENV=test.'
            );
        }

        [$secretKey, $webhookSecret] = $mode === self::MODE_PROD
            ? [$keys['liveKey'], $keys['liveWebhook']]
            : [$keys['testKey'], $keys['testWebhook']];

        // Une clé présente doit correspondre au mode : une sk_live_ rangée dans
        // la paire de test (ou l'inverse) trahit une erreur de saisie qu'il vaut
        // mieux arrêter net qu'encaisser au mauvais endroit.
        if ($secretKey !== '' && !str_starts_with($secretKey, self::PREFIX[$mode])) {
            throw new RuntimeException(sprintf(
                'La clé secrète Stripe ne correspond pas à STRIPE_ENV=%s (préfixe %s attendu).',
                $mode,
                self::PREFIX[$mode],
            ));
        }

        return new self($mode, $secretKey, $webhookSecret);
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    private static function mode(string $stripeEnv): string
    {
        $mode = $stripeEnv === '' ? self::MODE_TEST : strtolower(trim($stripeEnv));

        if (!in_array($mode, [self::MODE_TEST, self::MODE_PROD], true)) {
            throw new RuntimeException(sprintf(
                'STRIPE_ENV invalide : « %s » (attendu « test » ou « prod »).',
                $stripeEnv,
            ));
        }

        return $mode;
    }
}
