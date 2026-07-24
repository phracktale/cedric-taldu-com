<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Contact\ContactMessage;
use App\Domain\Contact\MessageStatus;
use App\Domain\Locale;
use App\Repository\ContactMessageRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;

#[CoversClass(ContactMessageRepository::class)]
#[CoversClass(ContactMessage::class)]
final class ContactMessageRepositoryTest extends DatabaseTestCase
{
    private function repository(): ContactMessageRepository
    {
        return new ContactMessageRepository($this->pdo);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-25 14:00:00', new DateTimeZone('UTC'));
    }

    public function test_un_message_general_est_enregistre_et_relu(): void
    {
        $repo = $this->repository();

        $id = $repo->store(new ContactMessage(
            id: null,
            artworkId: null,
            senderName: 'Camille Dupont',
            senderEmail: 'camille@example.com',
            subject: 'Demande de renseignement',
            body: "Bonjour,\nune question générale.",
            locale: Locale::Fr,
            status: MessageStatus::New,
            spamScore: 0,
            ipHash: str_repeat('a', 64),
            userAgent: 'Mozilla/5.0',
            createdAt: null,
        ), $this->now());

        $message = $repo->findById($id);

        $this->assertNotNull($message);
        $this->assertSame($id, $message->id);
        $this->assertNull($message->artworkId);
        $this->assertSame('Camille Dupont', $message->senderName);
        $this->assertSame('camille@example.com', $message->senderEmail);
        $this->assertSame("Bonjour,\nune question générale.", $message->body);
        $this->assertSame(Locale::Fr, $message->locale);
        $this->assertSame(MessageStatus::New, $message->status);
    }

    public function test_un_message_rattache_a_une_oeuvre_conserve_le_lien(): void
    {
        $categoryId = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        $artworkId = (new ArtworkFactory($this->pdo))->available()
            ->translated('fr', 'articulation', 'Articulation')->create($categoryId);

        $repo = $this->repository();

        $id = $repo->store(new ContactMessage(
            id: null,
            artworkId: $artworkId,
            senderName: 'Alex Martin',
            senderEmail: 'alex@example.com',
            subject: 'Question sur une œuvre',
            body: 'Cette œuvre est-elle toujours disponible ?',
            locale: Locale::Fr,
            status: MessageStatus::New,
            spamScore: 0,
            ipHash: null,
            userAgent: null,
            createdAt: null,
        ), $this->now());

        $message = $repo->findById($id);

        $this->assertNotNull($message);
        $this->assertSame($artworkId, $message->artworkId);
    }

    public function test_le_score_et_le_statut_indesirable_sont_conserves(): void
    {
        $repo = $this->repository();

        $id = $repo->store(new ContactMessage(
            id: null,
            artworkId: null,
            senderName: 'BOT',
            senderEmail: 'bot@example.com',
            subject: 'Message de contact',
            body: 'ACHETEZ VITE',
            locale: Locale::Fr,
            status: MessageStatus::Spam,
            spamScore: 4,
            ipHash: null,
            userAgent: null,
            createdAt: null,
        ), $this->now());

        $message = $repo->findById($id);

        $this->assertNotNull($message);
        $this->assertSame(MessageStatus::Spam, $message->status);
        $this->assertSame(4, $message->spamScore);
    }

    public function test_le_statut_evolue_et_est_relu(): void
    {
        $repo = $this->repository();

        $id = $repo->store(new ContactMessage(
            id: null,
            artworkId: null,
            senderName: 'Camille',
            senderEmail: 'camille@example.com',
            subject: 'Question',
            body: 'Bonjour.',
            locale: Locale::Fr,
            status: MessageStatus::New,
            spamScore: 0,
            ipHash: null,
            userAgent: null,
            createdAt: null,
        ), $this->now());

        $repo->updateStatus($id, MessageStatus::Answered);

        $message = $repo->findById($id);

        $this->assertNotNull($message);
        $this->assertSame(MessageStatus::Answered, $message->status);
    }

    public function test_un_identifiant_inconnu_ne_renvoie_rien(): void
    {
        $this->assertNull($this->repository()->findById(999999));
    }

    public function test_la_liste_rend_les_messages_du_plus_recent_au_plus_ancien(): void
    {
        $repo = $this->repository();
        $repo->store($this->message('Ancien'), new DateTimeImmutable('2026-07-20 10:00:00', new DateTimeZone('UTC')));
        $repo->store($this->message('Recent'), new DateTimeImmutable('2026-07-24 10:00:00', new DateTimeZone('UTC')));

        $messages = $repo->findAll(null, 10, 0);

        $this->assertCount(2, $messages);
        $this->assertSame('Recent', $messages[0]->senderName);
        $this->assertSame('Ancien', $messages[1]->senderName);
    }

    public function test_la_liste_filtre_par_statut(): void
    {
        $repo = $this->repository();
        $repo->store($this->message('Neuf', MessageStatus::New), $this->now());
        $repo->store($this->message('Indésirable', MessageStatus::Spam), $this->now());

        $indesirables = $repo->findAll(MessageStatus::Spam, 10, 0);

        $this->assertCount(1, $indesirables);
        $this->assertSame('Indésirable', $indesirables[0]->senderName);
    }

    public function test_le_comptage_par_statut(): void
    {
        $repo = $this->repository();
        $repo->store($this->message('A', MessageStatus::New), $this->now());
        $repo->store($this->message('B', MessageStatus::New), $this->now());
        $repo->store($this->message('C', MessageStatus::Answered), $this->now());

        $this->assertSame(2, $repo->countByStatus(MessageStatus::New));
        $this->assertSame(3, $repo->countByStatus(null));
    }

    public function test_un_message_se_supprime(): void
    {
        $repo = $this->repository();
        $id = $repo->store($this->message('À supprimer'), $this->now());

        $repo->delete($id);

        $this->assertNull($repo->findById($id));
    }

    private function message(string $name, MessageStatus $status = MessageStatus::New): ContactMessage
    {
        return new ContactMessage(
            id: null,
            artworkId: null,
            senderName: $name,
            senderEmail: 'x@example.com',
            subject: 'Sujet',
            body: 'Corps.',
            locale: Locale::Fr,
            status: $status,
            spamScore: 0,
            ipHash: null,
            userAgent: null,
            createdAt: null,
        );
    }
}
