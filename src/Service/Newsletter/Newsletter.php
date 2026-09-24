<?php

declare(strict_types=1);

namespace App\Service\Newsletter;

use App\Core\ClockInterface;
use App\Domain\Locale;
use App\Repository\NewsletterRepository;
use App\Service\I18n\Translator;

/**
 * Abonnement à la newsletter (revue du 2026-09-24).
 *
 * Toujours sur action explicite (case non précochée). La preuve du consentement
 * garde la FORMULATION présentée, dans la langue du visiteur : si le texte de la
 * case change, les abonnements antérieurs gardent celui qu'ils ont accepté.
 */
final class Newsletter
{
    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_CHECKOUT = 'checkout';
    public const SOURCE_ACCOUNT = 'account';

    public function __construct(
        private readonly NewsletterRepository $subscribers,
        private readonly Translator $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function subscribe(string $email, Locale $locale, string $source): void
    {
        $this->subscribers->subscribe(
            $email,
            $locale->value,
            $source,
            $this->translator->tRaw('newsletter.consent', $locale),
            $this->clock->now(),
        );
    }

    public function unsubscribe(string $email): bool
    {
        return $this->subscribers->unsubscribe($email, $this->clock->now());
    }
}
