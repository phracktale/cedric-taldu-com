<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Payment\Exception\InvalidWebhookSignature;

/**
 * Verification du schema de signature de Stripe.
 *
 *     Stripe-Signature: t=<horodatage>,v1=<hmac>,v1=<hmac>…
 *     hmac = HMAC-SHA256("<horodatage>.<corps brut>", secret du webhook)
 *
 * Partagee par la vraie passerelle et par son double : les tests eprouvent
 * ainsi la MEME cryptographie que la production. Un double qui repondrait
 * « oui » ferait passer WebhookTest sans rien prouver, et c'est le garde-fou
 * le plus important du lot.
 *
 * Plusieurs `v1` peuvent coexister pendant une rotation de secret : il suffit
 * qu'UNE corresponde.
 */
final class WebhookSignature
{
    /** Tolerance par defaut de Stripe, en secondes. */
    public const TOLERANCE = 300;

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, self::compute($payload, $secret, $timestamp));
    }

    /**
     * @throws InvalidWebhookSignature
     */
    public static function verify(string $payload, string $header, string $secret, ?int $now = null): void
    {
        if ($header === '') {
            throw InvalidWebhookSignature::because('en-tête absent');
        }

        [$timestamp, $signatures] = self::parse($header);

        if ($timestamp === null) {
            throw InvalidWebhookSignature::because('horodatage absent ou illisible');
        }

        if ($signatures === []) {
            throw InvalidWebhookSignature::because('aucune signature v1');
        }

        // Rejeu : une capture ancienne ne doit pas rester utilisable
        // indefiniment. Verifie APRES la forme, pour ne pas renseigner un
        // attaquant sur ce qui a echoue en premier.
        if ($now !== null && abs($now - $timestamp) > self::TOLERANCE) {
            throw InvalidWebhookSignature::because('horodatage hors tolérance');
        }

        $expected = self::compute($payload, $secret, $timestamp);

        foreach ($signatures as $signature) {
            // hash_equals : la comparaison naive s'arrete au premier octet
            // different, et le temps qu'elle prend renseigne sur le nombre
            // d'octets devines.
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw InvalidWebhookSignature::because('aucune signature ne correspond');
    }

    private static function compute(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /**
     * @return array{int|null, list<string>}
     */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && preg_match('/^\d+$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
