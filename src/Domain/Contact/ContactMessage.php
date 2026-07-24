<?php

declare(strict_types=1);

namespace App\Domain\Contact;

use App\Domain\Locale;
use DateTimeImmutable;

/**
 * Un message reçu par le formulaire de contact, général ou rattaché à une œuvre.
 *
 * Value object figé, employé aussi bien pour l'écriture (id et createdAt à
 * `null`, la base les attribue) que pour la relecture en back-office. Le SUJET
 * est fixé côté serveur (06-securite §6.6) : le texte libre du visiteur ne vit
 * que dans `body`, jamais dans un en-tête d'e-mail.
 */
final class ContactMessage
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $artworkId,
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $subject,
        public readonly string $body,
        public readonly Locale $locale,
        public readonly MessageStatus $status,
        public readonly int $spamScore,
        public readonly ?string $ipHash,
        public readonly ?string $userAgent,
        public readonly ?DateTimeImmutable $createdAt,
    ) {
    }

    public function isAboutArtwork(): bool
    {
        return $this->artworkId !== null;
    }
}
