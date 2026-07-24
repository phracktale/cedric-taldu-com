<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Spam;

use App\Service\Spam\FormTimestamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FrozenClock;

/**
 * Horodatage signé d'un formulaire (06-securite §6.2).
 *
 * Le champ porte l'instant de génération, signé par HMAC : un robot qui
 * soumet dans la seconde ou qui rejoue un formulaire vieux de trois heures
 * se trahit. La signature empêche de forger un instant crédible.
 */
#[CoversClass(FormTimestamp::class)]
final class FormTimestampTest extends TestCase
{
    private const PEPPER = 'poivre-de-test-0123456789abcdef';

    public function test_un_jeton_fraichement_emis_a_un_age_nul(): void
    {
        $clock = new FrozenClock('2026-07-21 09:30:00');
        $timestamp = new FormTimestamp(self::PEPPER, $clock);

        $token = $timestamp->issue();

        $this->assertSame(0, $timestamp->elapsed($token));
    }

    public function test_l_age_suit_l_ecoulement_de_l_horloge(): void
    {
        $clock = new FrozenClock('2026-07-21 09:30:00');
        $timestamp = new FormTimestamp(self::PEPPER, $clock);

        $token = $timestamp->issue();
        $clock->advance('+42 seconds');

        $this->assertSame(42, $timestamp->elapsed($token));
    }

    public function test_une_signature_falsifiee_est_rejetee(): void
    {
        $clock = new FrozenClock('2026-07-21 09:30:00');
        $timestamp = new FormTimestamp(self::PEPPER, $clock);

        // L'attaquant remplace l'instant par un plus vieux (formulaire « âgé »
        // de 10 s) sans connaître la clé : la signature ne suit pas.
        $forge = dechex($clock->now()->getTimestamp() - 10) . '-' . str_repeat('0', 64);

        $this->assertNull($timestamp->elapsed($forge));
    }

    public function test_un_jeton_malforme_est_rejete(): void
    {
        $clock = new FrozenClock('2026-07-21 09:30:00');
        $timestamp = new FormTimestamp(self::PEPPER, $clock);

        $this->assertNull($timestamp->elapsed('nimportequoi'));
        $this->assertNull($timestamp->elapsed('zzzz-' . str_repeat('0', 64)));
        $this->assertNull($timestamp->elapsed(''));
    }

    public function test_deux_poivres_differents_ne_partagent_pas_les_jetons(): void
    {
        $clock = new FrozenClock('2026-07-21 09:30:00');
        $emetteur = new FormTimestamp(self::PEPPER, $clock);
        $autre = new FormTimestamp('un-tout-autre-poivre-secret-9999', $clock);

        $token = $emetteur->issue();

        $this->assertNull($autre->elapsed($token));
    }

    public function test_un_jeton_anterieur_a_l_emission_est_rejete(): void
    {
        // Un instant dans le futur (horloge reculée après émission) donnerait un
        // âge négatif : signe d'une manipulation, refus franc.
        $clock = new FrozenClock('2026-07-21 09:30:00');
        $timestamp = new FormTimestamp(self::PEPPER, $clock);

        $token = $timestamp->issue();
        $clock->advance('-5 seconds');

        $this->assertNull($timestamp->elapsed($token));
    }
}
