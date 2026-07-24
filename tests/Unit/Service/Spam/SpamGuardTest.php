<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Spam;

use App\Domain\Locale;
use App\Service\Spam\FormTimestamp;
use App\Service\Spam\SpamGuard;
use App\Service\Spam\SpamHeuristics;
use App\Service\Spam\SpamSignals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeThrottle;
use Tests\Support\Doubles\FrozenClock;

/**
 * Garde anti-spam des formulaires publics (06-securite §6).
 *
 * Trois verdicts possibles : ACCEPTÉ (message enregistré, artiste notifié),
 * REJETÉ silencieusement (honeypot, horodatage, débit — réponse de succès mais
 * rien n'est enregistré ni notifié, pour ne pas renseigner le robot), et
 * SIGNALÉ (score d'heuristiques trop haut — enregistré en `spam`, sans notification).
 */
#[CoversClass(SpamGuard::class)]
#[CoversClass(SpamSignals::class)]
final class SpamGuardTest extends TestCase
{
    private const PEPPER = 'poivre-de-test-0123456789abcdef';

    private function guard(FrozenClock $clock, FakeThrottle $throttle): SpamGuard
    {
        return new SpamGuard(new FormTimestamp(self::PEPPER, $clock), $throttle, new SpamHeuristics());
    }

    private function validToken(FrozenClock $clock): string
    {
        // Émis 30 s plus tôt : au-delà des 3 s minimales, en deçà des 2 h.
        $token = (new FormTimestamp(self::PEPPER, $clock))->issue();
        $clock->advance('+30 seconds');

        return $token;
    }

    public function test_un_envoi_humain_normal_est_accepte(): void
    {
        $clock = new FrozenClock();
        $throttle = new FakeThrottle();
        $token = $this->validToken($clock);

        $verdict = $this->guard($clock, $throttle)->evaluate(new SpamSignals(
            honeypot: '',
            timestamp: $token,
            clientIp: '203.0.113.7',
            message: 'Bonjour, cette œuvre est-elle encore disponible ? Merci à vous.',
            locale: Locale::Fr,
        ));

        $this->assertTrue($verdict->isAccepted());
        $this->assertTrue($verdict->shouldPersist());
        $this->assertTrue($verdict->shouldNotify());
        $this->assertSame('new', $verdict->status());
    }

    public function test_un_honeypot_rempli_est_rejete_silencieusement(): void
    {
        $clock = new FrozenClock();
        $token = $this->validToken($clock);

        $verdict = $this->guard($clock, new FakeThrottle())->evaluate(new SpamSignals(
            honeypot: 'http://robot.example',
            timestamp: $token,
            clientIp: '203.0.113.7',
            message: 'peu importe',
            locale: Locale::Fr,
        ));

        $this->assertTrue($verdict->isRejected());
        $this->assertFalse($verdict->shouldPersist());
        $this->assertFalse($verdict->shouldNotify());
    }

    public function test_une_soumission_trop_rapide_est_rejetee(): void
    {
        $clock = new FrozenClock();
        // Jeton émis « maintenant » et soumis dans la seconde : robot.
        $token = (new FormTimestamp(self::PEPPER, $clock))->issue();
        $clock->advance('+1 second');

        $verdict = $this->guard($clock, new FakeThrottle())->evaluate(new SpamSignals(
            honeypot: '',
            timestamp: $token,
            clientIp: '203.0.113.7',
            message: 'Bonjour, une question sur cette œuvre.',
            locale: Locale::Fr,
        ));

        $this->assertTrue($verdict->isRejected());
    }

    public function test_un_formulaire_evente_est_rejete(): void
    {
        $clock = new FrozenClock();
        $token = (new FormTimestamp(self::PEPPER, $clock))->issue();
        $clock->advance('+3 hours');

        $verdict = $this->guard($clock, new FakeThrottle())->evaluate(new SpamSignals(
            honeypot: '',
            timestamp: $token,
            clientIp: '203.0.113.7',
            message: 'Bonjour, une question sur cette œuvre.',
            locale: Locale::Fr,
        ));

        $this->assertTrue($verdict->isRejected());
    }

    public function test_un_horodatage_falsifie_est_rejete(): void
    {
        $clock = new FrozenClock();

        $verdict = $this->guard($clock, new FakeThrottle())->evaluate(new SpamSignals(
            honeypot: '',
            timestamp: 'ffffffff-' . str_repeat('0', 64),
            clientIp: '203.0.113.7',
            message: 'Bonjour, une question sur cette œuvre.',
            locale: Locale::Fr,
        ));

        $this->assertTrue($verdict->isRejected());
    }

    public function test_au_dela_de_la_limite_horaire_l_envoi_est_rejete(): void
    {
        $clock = new FrozenClock();
        $throttle = new FakeThrottle();
        $guard = $this->guard($clock, $throttle);

        // Les trois premiers passent, le quatrième est de trop (3/heure).
        $accepts = 0;
        for ($i = 0; $i < 4; $i++) {
            $token = $this->validToken($clock);
            $verdict = $guard->evaluate(new SpamSignals(
                honeypot: '',
                timestamp: $token,
                clientIp: '203.0.113.7',
                message: 'Bonjour, une question légitime sur une œuvre.',
                locale: Locale::Fr,
            ));
            if ($verdict->isAccepted()) {
                $accepts++;
            }
        }

        $this->assertSame(3, $accepts);
    }

    public function test_une_ip_differente_n_est_pas_penalisee_par_une_autre(): void
    {
        $clock = new FrozenClock();
        $throttle = new FakeThrottle();
        $guard = $this->guard($clock, $throttle);

        // Une IP épuise son quota horaire.
        for ($i = 0; $i < 4; $i++) {
            $token = $this->validToken($clock);
            $guard->evaluate(new SpamSignals('', $token, '203.0.113.7', 'Question.', Locale::Fr));
        }

        // Une autre IP reste libre.
        $token = $this->validToken($clock);
        $verdict = $guard->evaluate(new SpamSignals('', $token, '198.51.100.9', 'Bonjour, une question sur l’œuvre.', Locale::Fr));

        $this->assertTrue($verdict->isAccepted());
    }

    public function test_un_message_a_score_eleve_est_signale_mais_conserve(): void
    {
        $clock = new FrozenClock();
        $token = $this->validToken($clock);

        $verdict = $this->guard($clock, new FakeThrottle())->evaluate(new SpamSignals(
            honeypot: '',
            timestamp: $token,
            clientIp: '203.0.113.7',
            message: 'ACHETEZ ICI HTTP://A.EXAMPLE ET HTTPS://B.EXAMPLE ET WWW.C.EXAMPLE VITE VITE',
            locale: Locale::Fr,
        ));

        // Ni accepté (pas de notification), ni rejeté (conservé pour consultation).
        $this->assertFalse($verdict->isAccepted());
        $this->assertFalse($verdict->isRejected());
        $this->assertTrue($verdict->shouldPersist());
        $this->assertFalse($verdict->shouldNotify());
        $this->assertSame('spam', $verdict->status());
        $this->assertGreaterThanOrEqual(3, $verdict->score);
    }
}
