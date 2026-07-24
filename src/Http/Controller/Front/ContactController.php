<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\ClockInterface;
use App\Core\Exception\ValidationFailed;
use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Rule;
use App\Core\Validator;
use App\Core\View;
use App\Domain\Contact\ContactMessage;
use App\Domain\Contact\MessageStatus;
use App\Domain\Catalog\Artwork;
use App\Domain\Exception\InvalidSlug;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\ArtworkRepository;
use App\Repository\ContactMessageRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\Mail\ContactMailer;
use App\Service\Spam\SpamGuard;
use App\Service\Spam\SpamSignals;
use App\Service\Spam\FormTimestamp;
use App\Service\View\Chrome;

/**
 * Formulaire de contact, général ou rattaché à une œuvre (02-front §6, §4.6).
 *
 * Le bouton « Poser une question » d'une fiche œuvre pointe, sans JavaScript,
 * vers `/{locale}/contact?oeuvre={slug}` : le formulaire pré-remplit alors le
 * contexte de l'œuvre. Tout passe par le {@see SpamGuard} — honeypot, horodatage
 * signé, débit, heuristiques — avant le moindre enregistrement.
 *
 * Le SUJET de l'e-mail est composé côté serveur (06-securite §6.6) ; le texte
 * du visiteur ne vit que dans le corps. L'adresse IP n'est stockée que hachée
 * (06-securite §9).
 */
final class ContactController
{
    /** Champ appât : rempli par un robot, invisible pour un humain (06-securite §6.1). */
    private const HONEYPOT = 'site_web';

    /** Champ caché portant l'horodatage signé (06-securite §6.2). */
    private const TIMESTAMP = 'ts';

    private const MESSAGE_MAX = 3000;

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly ContactMessageRepository $messages,
        private readonly ArtworkRepository $artworks,
        private readonly SpamGuard $spamGuard,
        private readonly FormTimestamp $timestamp,
        private readonly ContactMailer $mailer,
        private readonly Validator $validator,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly string $pepper,
    ) {
    }

    public function form(Request $request): Response
    {
        $locale = self::locale($request);
        $artwork = $this->resolveArtwork($request->query('oeuvre'), $locale);

        return Response::html($this->view->render('front/contact', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => 'Contact',
            'submitUrl' => $this->url->route('contact.submit', ['locale' => $locale->value]),
            'honeypot' => self::HONEYPOT,
            'timestampField' => self::TIMESTAMP,
            'timestamp' => $this->timestamp->issue(),
            'artwork' => $artwork,
            'artworkSlug' => $artwork?->slug($locale)->value,
            'sent' => $request->query('envoye') === '1',
            'error' => null,
            'values' => [],
        ], layout: 'layouts/public'));
    }

    public function submit(Request $request): Response
    {
        $locale = self::locale($request);

        $verdict = $this->spamGuard->evaluate(new SpamSignals(
            honeypot: $request->input(self::HONEYPOT) ?? '',
            timestamp: $request->input(self::TIMESTAMP) ?? '',
            clientIp: $request->clientIp,
            message: $request->input('message') ?? '',
            locale: $locale,
        ));

        // Rejet silencieux (honeypot, horodatage, débit) : on répond comme un
        // succès, sans rien enregistrer ni notifier. Le robot n'apprend rien.
        if ($verdict->isRejected()) {
            $this->logger->log(LogLevel::Warning, 'Message de contact rejeté', ['motif' => $verdict->reason]);

            return $this->confirmed($locale);
        }

        // L'œuvre éventuelle est résolue hors validation : un slug inconnu ou
        // trafiqué dégrade vers un message général, il ne bloque pas l'envoi.
        $artwork = $this->resolveArtwork($request->input('oeuvre'), $locale);

        try {
            $clean = $this->validator->validate(
                [
                    'nom' => $request->input('nom') ?? '',
                    'email' => $request->input('email') ?? '',
                    'message' => $request->input('message') ?? '',
                ],
                [
                    'nom' => Rule::text(160),
                    'email' => Rule::email(),
                    'message' => Rule::multiline(self::MESSAGE_MAX),
                ],
            );
        } catch (ValidationFailed $failure) {
            return $this->rejectForm($request, $locale, $artwork, $failure);
        }

        $subject = $artwork !== null
            ? 'Question sur une œuvre : ' . $artwork->title($locale)
            : 'Message de contact';

        $message = new ContactMessage(
            id: null,
            artworkId: $artwork?->id,
            senderName: (string) $clean['nom'],
            senderEmail: (string) $clean['email'],
            subject: mb_substr($subject, 0, 220),
            body: (string) $clean['message'],
            locale: $locale,
            status: MessageStatus::from($verdict->status()),
            spamScore: $verdict->score,
            ipHash: hash('sha256', $request->clientIp . "\0" . $this->pepper),
            userAgent: self::userAgent($request),
            createdAt: null,
        );

        $id = $this->messages->store($message, $this->clock->now());

        // Un message signalé (indésirable) est conservé pour consultation, mais
        // ne dérange pas l'artiste. Seul un message accepté le notifie.
        if ($verdict->shouldNotify()) {
            $this->sendNotification($message, $id, $artwork, $locale);
        }

        return $this->confirmed($locale);
    }

    // ------------------------------------------------------------ assistance

    private function sendNotification(ContactMessage $message, int $id, ?Artwork $artwork, Locale $locale): void
    {
        // Un échec d'e-mail ne doit jamais faire perdre un message reçu
        // (même principe que les e-mails de commande, 03-boutique §7).
        try {
            $this->mailer->notify(
                $message,
                $artwork?->title($locale),
                // Lien absolu vers la boîte de réception : l'e-mail est lu hors
                // du site, l'URL doit donc être complète (jamais l'en-tête Host).
                $this->url->absolute('admin.message.show', ['id' => $id]),
            );
        } catch (\Throwable $exception) {
            $this->logger->log(LogLevel::Error, 'Notification de contact non envoyée', [
                'message_id' => $id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveArtwork(?string $rawSlug, Locale $locale): ?Artwork
    {
        if ($rawSlug === null || $rawSlug === '') {
            return null;
        }

        try {
            $slug = Slug::fromString($rawSlug);
        } catch (InvalidSlug) {
            return null;
        }

        return $this->artworks->findBySlug($locale, $slug);
    }

    private function rejectForm(Request $request, Locale $locale, ?Artwork $artwork, ValidationFailed $failure): Response
    {
        return Response::html($this->view->render('front/contact', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => 'Contact',
            'submitUrl' => $this->url->route('contact.submit', ['locale' => $locale->value]),
            'honeypot' => self::HONEYPOT,
            'timestampField' => self::TIMESTAMP,
            'timestamp' => $this->timestamp->issue(),
            'artwork' => $artwork,
            'artworkSlug' => $artwork?->slug($locale)->value,
            'sent' => false,
            'error' => 'Le formulaire comporte des erreurs.',
            'errors' => $failure->errors(),
            'values' => [
                'nom' => (string) ($request->input('nom') ?? ''),
                'email' => (string) ($request->input('email') ?? ''),
                'message' => (string) ($request->input('message') ?? ''),
            ],
        ], layout: 'layouts/public'), 422);
    }

    private function confirmed(Locale $locale): Response
    {
        // Motif « Post/Redirect/Get » : un rafraîchissement ne renvoie pas le
        // message. Rejet silencieux et vrai succès aboutissent à la même URL,
        // ce qui est exactement l'indistinguabilité recherchée.
        return RedirectResponse::to(
            $this->url->route('contact.form', ['locale' => $locale->value]) . '?envoye=1',
            303,
        );
    }

    private static function userAgent(Request $request): ?string
    {
        $agent = $request->header('User-Agent');

        if ($agent === null || $agent === '') {
            return null;
        }

        return mb_substr(str_replace(["\r", "\n", "\0"], '', $agent), 0, 255);
    }

    private static function locale(Request $request): Locale
    {
        return Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
    }
}
