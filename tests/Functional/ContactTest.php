<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\ClockInterface;
use App\Core\Csrf;
use App\Service\Mail\ArrayMailer;
use App\Service\Mail\MailerInterface;
use App\Service\Spam\FormTimestamp;
use Tests\Support\Doubles\FrozenClock;
use Tests\Support\FunctionalTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;

/**
 * Formulaire de contact de bout en bout (02-front §6, critère du lot 4).
 *
 * Un visiteur envoie une question rattachée à une œuvre ; elle est enregistrée
 * et l'artiste est notifié. Le honeypot et l'horodatage rejettent en silence.
 * Le SpamGuard est éprouvé finement en unitaire ; ici on prouve le PARCOURS.
 */
final class ContactTest extends FunctionalTestCase
{
    private const PEPPER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ArrayMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new ArrayMailer();
        // Horloge gelée : l'horodatage signé doit paraître âgé de 3 s à 2 h.
        $this->withService(ClockInterface::class, static fn (): ClockInterface => new FrozenClock('2026-07-25 14:00:30'));
        // Aucun envoi SMTP réel en test : on inspecte les messages capturés.
        $this->withService(MailerInterface::class, fn (): MailerInterface => $this->mailer);
    }

    public function test_le_formulaire_de_contact_s_affiche(): void
    {
        $response = $this->get('/cedric-taldu/fr/contact');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="message"', $response->body);
        $this->assertStringContainsString('name="ts"', $response->body);
        $this->assertStringContainsString('name="site_web"', $response->body);
    }

    public function test_une_question_sur_une_oeuvre_prefixe_le_contexte(): void
    {
        $categoryId = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->available()
            ->translated('fr', 'articulation', 'Articulation')->create($categoryId);

        $response = $this->get('/cedric-taldu/fr/contact?oeuvre=articulation');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Articulation', $response->body);
        $this->assertStringContainsString('name="oeuvre"', $response->body);
    }

    public function test_un_envoi_valide_enregistre_le_message_et_notifie_l_artiste(): void
    {
        $response = $this->submit([
            'nom' => 'Camille Dupont',
            'email' => 'camille@example.com',
            'message' => 'Bonjour, cette œuvre est-elle encore disponible ? Merci à vous.',
        ]);

        $this->assertSame(303, $response->status);
        $this->assertStringContainsString('envoye=1', (string) $response->header('Location'));

        $this->assertSame(1, (int) $this->valeur('SELECT COUNT(*) FROM contact_messages'));
        $this->assertSame('new', (string) $this->valeur("SELECT status FROM contact_messages"));
        $this->assertSame('camille@example.com', (string) $this->valeur('SELECT sender_email FROM contact_messages'));

        $this->assertNotNull($this->mailer->lastTo('contact@cedrictaldu.com'));
    }

    public function test_une_question_sur_une_oeuvre_conserve_le_lien(): void
    {
        $categoryId = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        $artworkId = (new ArtworkFactory($this->pdo))->available()
            ->translated('fr', 'articulation', 'Articulation')->create($categoryId);

        $this->submit([
            'nom' => 'Alex Martin',
            'email' => 'alex@example.com',
            'message' => 'Bonjour, une question précise sur cette œuvre unique.',
            'oeuvre' => 'articulation',
        ]);

        $this->assertSame($artworkId, (int) $this->valeur('SELECT artwork_id FROM contact_messages'));
    }

    public function test_un_honeypot_rempli_ne_cree_aucun_message(): void
    {
        $response = $this->submit([
            'nom' => 'Robot',
            'email' => 'robot@example.com',
            'message' => 'Message indésirable.',
            'site_web' => 'http://robot.example',
        ]);

        // Réponse d'apparence normale, mais rien n'est enregistré ni notifié.
        $this->assertSame(303, $response->status);
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM contact_messages'));
        $this->assertNull($this->mailer->lastTo('contact@cedrictaldu.com'));
    }

    public function test_un_champ_manquant_reaffiche_le_formulaire_en_erreur(): void
    {
        $response = $this->submit([
            'nom' => 'Camille',
            'email' => '',
            'message' => 'Bonjour.',
        ]);

        $this->assertSame(422, $response->status);
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM contact_messages'));
    }

    public function test_un_envoi_trop_rapide_est_rejete_sans_message(): void
    {
        // Jeton émis « maintenant » (14:00:30) et soumis dans la même seconde.
        $token = (new FormTimestamp(self::PEPPER, new FrozenClock('2026-07-25 14:00:30')))->issue();

        $response = $this->requete('POST', '/cedric-taldu/fr/contact', post: [
            Csrf::FIELD => $this->jeton(),
            'ts' => $token,
            'nom' => 'Camille',
            'email' => 'camille@example.com',
            'message' => 'Bonjour, une question.',
        ]);

        $this->assertSame(303, $response->status);
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM contact_messages'));
    }

    // ------------------------------------------------------------ assistance

    /**
     * @param array<string, string> $champs
     */
    private function submit(array $champs): \App\Core\Response
    {
        return $this->requete('POST', '/cedric-taldu/fr/contact', post: [
            Csrf::FIELD => $this->jeton(),
            'ts' => $this->validTimestamp(),
            ...$champs,
        ]);
    }

    /** Horodatage émis 30 s avant l'horloge gelée : accepté. */
    private function validTimestamp(): string
    {
        return (new FormTimestamp(self::PEPPER, new FrozenClock('2026-07-25 14:00:00')))->issue();
    }

    private function jeton(): string
    {
        $jeton = $this->session->get(Csrf::SESSION_KEY);

        if (!is_string($jeton) || $jeton === '') {
            $jeton = str_repeat('a', 64);
            $this->session->set(Csrf::SESSION_KEY, $jeton);
        }

        return $jeton;
    }

    private function valeur(string $sql): string|int|null
    {
        $value = $this->pdo->query($sql)->fetchColumn();

        return $value === false ? null : $value;
    }
}
