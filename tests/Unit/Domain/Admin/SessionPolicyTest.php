<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use App\Domain\Admin\SessionPolicy;
use App\Domain\Admin\SessionStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * 06-securite §4 : « Inactivite 30 min, duree absolue 12 h, empreinte faible
 * (user-agent + reseau /24) verifiee pour detecter un vol de session grossier —
 * sans bloquer un changement d'IP legitime. »
 *
 * Trois regles, trois raisons de fermer une session, et un verdict distinct pour
 * chacune : le journal de securite doit pouvoir dire « empreinte etrangere » et
 * non « session expiree », sinon un vol de cookie passe pour une inactivite.
 */
final class SessionPolicyTest extends TestCase
{
    private const POIVRE = 'poivre-de-test-suffisamment-long-pour-config';
    private const OUVERTURE = '2026-07-21 09:00:00';
    private const AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)';

    private SessionPolicy $politique;

    protected function setUp(): void
    {
        $this->politique = new SessionPolicy(self::POIVRE);
    }

    // ------------------------------------------------------------ inactivite

    public function test_une_session_fraiche_est_valide(): void
    {
        $verdict = $this->verdict(vueA: '2026-07-21 09:00:00', maintenant: '2026-07-21 09:20:00');

        $this->assertSame(SessionStatus::Valid, $verdict);
    }

    public function test_une_session_inactive_depuis_trente_minutes_est_close(): void
    {
        $this->assertSame(
            SessionStatus::Valid,
            $this->verdict(vueA: '2026-07-21 09:00:00', maintenant: '2026-07-21 09:29:59'),
        );

        $this->assertSame(
            SessionStatus::IdleTimeout,
            $this->verdict(vueA: '2026-07-21 09:00:00', maintenant: '2026-07-21 09:30:00'),
        );
    }

    public function test_l_activite_repousse_l_inactivite_mais_pas_la_duree_absolue(): void
    {
        // Une session entretenue reste ouverte... jusqu'a la borne absolue.
        $this->assertSame(
            SessionStatus::Valid,
            $this->verdict(vueA: '2026-07-21 20:50:00', maintenant: '2026-07-21 20:59:00'),
        );

        $this->assertSame(
            SessionStatus::AbsoluteTimeout,
            $this->verdict(vueA: '2026-07-21 21:00:00', maintenant: '2026-07-21 21:00:01'),
        );
    }

    public function test_la_duree_absolue_est_de_douze_heures(): void
    {
        $this->assertSame(
            SessionStatus::Valid,
            $this->verdict(vueA: '2026-07-21 20:59:59', maintenant: '2026-07-21 20:59:59'),
        );

        $this->assertSame(
            SessionStatus::AbsoluteTimeout,
            $this->verdict(vueA: '2026-07-21 21:00:00', maintenant: '2026-07-21 21:00:00'),
        );
    }

    public function test_l_expiration_absolue_prime_sur_l_inactivite(): void
    {
        // Une session a la fois inactive et trop vieille est signalee comme trop
        // vieille : c'est la borne qu'aucune activite ne peut repousser.
        $verdict = $this->verdict(vueA: '2026-07-21 15:00:00', maintenant: '2026-07-22 09:00:00');

        $this->assertSame(SessionStatus::AbsoluteTimeout, $verdict);
    }

    // -------------------------------------------------------------- empreinte

    public function test_un_changement_d_adresse_dans_le_meme_reseau_ne_ferme_rien(): void
    {
        // Cas legitime et frequent : bail DHCP renouvele, passage d'un point
        // d'acces a un autre. Fermer la session la rendrait inutilisable.
        $verdict = $this->verdict(
            maintenant: '2026-07-21 09:10:00',
            ipCourante: '203.0.113.212',
        );

        $this->assertSame(SessionStatus::Valid, $verdict);
    }

    public function test_un_changement_de_reseau_ferme_la_session(): void
    {
        $verdict = $this->verdict(
            maintenant: '2026-07-21 09:10:00',
            ipCourante: '198.51.100.7',
        );

        $this->assertSame(SessionStatus::FingerprintMismatch, $verdict);
    }

    public function test_un_changement_de_navigateur_ferme_la_session(): void
    {
        // Le cookie recopie dans un autre navigateur est le vol grossier que
        // cette empreinte doit attraper.
        $verdict = $this->verdict(
            maintenant: '2026-07-21 09:10:00',
            agentCourant: 'curl/8.4.0',
        );

        $this->assertSame(SessionStatus::FingerprintMismatch, $verdict);
    }

    public function test_l_empreinte_ne_revele_ni_l_adresse_ni_le_navigateur(): void
    {
        // L'empreinte est stockee en session et journalisee : elle ne doit pas
        // etre une donnee personnelle en clair (06-securite §9).
        $empreinte = $this->politique->fingerprint(self::AGENT, '203.0.113.7');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $empreinte);
        $this->assertStringNotContainsString('203.0.113', $empreinte);
        $this->assertStringNotContainsString('Mozilla', $empreinte);
    }

    public function test_deux_poivres_differents_donnent_deux_empreintes_differentes(): void
    {
        // Sans poivre, l'empreinte serait reconstituable par quiconque connait
        // l'adresse et le navigateur de la cible.
        $autre = new SessionPolicy('un-autre-poivre-tout-aussi-long-que-le-premier');

        $this->assertNotSame(
            $this->politique->fingerprint(self::AGENT, '203.0.113.7'),
            $autre->fingerprint(self::AGENT, '203.0.113.7'),
        );
    }

    public function test_une_adresse_ipv6_est_regroupee_par_prefixe_de_64_bits(): void
    {
        // L'equivalent IPv6 du /24 : le prefixe stable attribue a un abonne. Sans
        // ce traitement, chaque nouvelle adresse temporaire d'un client IPv6
        // fermerait la session.
        $reference = $this->politique->fingerprint(self::AGENT, '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd');

        $this->assertSame(
            $reference,
            $this->politique->fingerprint(self::AGENT, '2001:db8:1234:5678:1111:2222:3333:4444'),
        );

        $this->assertNotSame(
            $reference,
            $this->politique->fingerprint(self::AGENT, '2001:db8:1234:9999:aaaa:bbbb:cccc:dddd'),
        );
    }

    public function test_une_adresse_illisible_reste_comparable_a_elle_meme(): void
    {
        // Une valeur qui n'est pas une IP ne doit ni faire tomber la page, ni
        // devenir un joker qui vaudrait pour toutes les autres.
        $this->assertSame(
            $this->politique->fingerprint(self::AGENT, 'inconnue'),
            $this->politique->fingerprint(self::AGENT, 'inconnue'),
        );

        $this->assertNotSame(
            $this->politique->fingerprint(self::AGENT, 'inconnue'),
            $this->politique->fingerprint(self::AGENT, '203.0.113.7'),
        );
    }

    // --------------------------------------------------------------- outils

    private function verdict(
        string $vueA = '2026-07-21 09:00:00',
        string $maintenant = '2026-07-21 09:10:00',
        string $ipCourante = '203.0.113.7',
        string $agentCourant = self::AGENT,
    ): SessionStatus {
        return $this->politique->verdict(
            issuedAt: $this->instant(self::OUVERTURE),
            lastSeenAt: $this->instant($vueA),
            fingerprint: $this->politique->fingerprint(self::AGENT, '203.0.113.7'),
            now: $this->instant($maintenant),
            currentFingerprint: $this->politique->fingerprint($agentCourant, $ipCourante),
        );
    }

    private function instant(string $valeur): DateTimeImmutable
    {
        return new DateTimeImmutable($valeur, new DateTimeZone('UTC'));
    }
}
