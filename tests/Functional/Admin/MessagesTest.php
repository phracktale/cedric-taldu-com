<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Domain\Contact\ContactMessage;
use App\Domain\Contact\MessageStatus;
use App\Domain\Locale;
use App\Repository\ContactMessageRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * 04-back-office §10 : boîte de réception des messages.
 *
 * L'artiste consulte ce que le formulaire de contact a reçu, le classe, et
 * répond depuis son propre client de messagerie (lien mailto). L'envoi ne passe
 * jamais par le site.
 */
final class MessagesTest extends AdminTestCase
{
    private const MESSAGES = '/cedric-taldu/admin/messages';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    private function store(ContactMessage $message): int
    {
        return (new ContactMessageRepository($this->pdo))
            ->store($message, new DateTimeImmutable('2026-07-25 10:00:00', new DateTimeZone('UTC')));
    }

    private function message(
        string $name = 'Camille',
        MessageStatus $status = MessageStatus::New,
        ?int $artworkId = null,
    ): ContactMessage {
        return new ContactMessage(
            id: null,
            artworkId: $artworkId,
            senderName: $name,
            senderEmail: 'camille@example.com',
            subject: 'Une question',
            body: 'Bonjour, une question.',
            locale: Locale::Fr,
            status: $status,
            spamScore: 0,
            ipHash: null,
            userAgent: null,
            createdAt: null,
        );
    }

    public function test_la_boite_liste_les_messages_recus(): void
    {
        $this->store($this->message('Camille Dupont'));

        $reponse = $this->get(self::MESSAGES);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Camille Dupont', $reponse->body);
    }

    public function test_ouvrir_un_message_neuf_le_marque_lu(): void
    {
        $id = $this->store($this->message());

        $reponse = $this->get(self::MESSAGES . '/' . $id);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Bonjour, une question.', $reponse->body);
        $this->assertSame('read', $this->statut($id));
    }

    public function test_le_detail_propose_une_reponse_par_mailto(): void
    {
        $id = $this->store($this->message());

        $reponse = $this->get(self::MESSAGES . '/' . $id);

        // La réponse ne passe pas par le site (04-back-office §10).
        $this->assertStringContainsString('mailto:camille@example.com', $reponse->body);
    }

    public function test_un_message_peut_etre_marque_repondu(): void
    {
        $id = $this->store($this->message());

        $reponse = $this->postAvecJeton(self::MESSAGES . '/' . $id . '/statut', ['statut' => 'answered']);

        $this->assertSame(302, $reponse->status);
        $this->assertSame('answered', $this->statut($id));
    }

    public function test_le_filtre_par_statut_ne_montre_que_les_indesirables(): void
    {
        $this->store($this->message('Légitime', MessageStatus::New));
        $this->store($this->message('Robot', MessageStatus::Spam));

        $corps = $this->get(self::MESSAGES . '?statut=spam')->body;

        $this->assertStringContainsString('Robot', $corps);
        $this->assertStringNotContainsString('Légitime', $corps);
    }

    public function test_une_question_sur_une_oeuvre_nomme_l_oeuvre(): void
    {
        $categoryId = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        $artworkId = (new ArtworkFactory($this->pdo))->available()
            ->translated('fr', 'articulation', 'Articulation')->create($categoryId);

        $id = $this->store($this->message('Alex', MessageStatus::New, $artworkId));

        $corps = $this->get(self::MESSAGES . '/' . $id)->body;

        $this->assertStringContainsString('Articulation', $corps);
    }

    public function test_un_message_se_supprime(): void
    {
        $id = $this->store($this->message());

        $this->postAvecJeton(self::MESSAGES . '/' . $id . '/suppression');

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
        $this->assertSame(0, $count);
    }

    private function statut(int $id): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM contact_messages WHERE id = :id');
        $statement->execute(['id' => $id]);

        return (string) $statement->fetchColumn();
    }
}
