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
}
