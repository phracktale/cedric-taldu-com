<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Locale;
use App\Repository\NewsletterRepository;
use App\Service\Export\CsvWriter;
use App\Service\I18n\UrlGenerator;
use App\Service\Newsletter\Newsletter;
use App\Service\Newsletter\UnsubscribeToken;
use App\Service\View\AdminChrome;

/**
 * Abonnés à la newsletter (revue du 2026-09-24).
 *
 * Le site ne fait pas l'envoi : l'artiste exporte la liste vers son outil
 * d'envoi, avec pour chaque abonné son lien de désinscription signé, à placer
 * dans chaque message. La preuve du consentement (date, source, texte) suit.
 */
final class NewsletterController
{
    private const EXPORT_HEADERS = ['email', 'langue', 'source', 'consentement', 'texte', 'desinscription'];

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly NewsletterRepository $subscribers,
        private readonly Newsletter $newsletter,
        private readonly UnsubscribeToken $tokens,
        private readonly UrlGenerator $url,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/newsletter/index', [
            'titre' => 'Newsletter',
            'abonnes' => $this->subscribers->findActive(),
        ]);
    }

    public function export(Request $request): Response
    {
        $rows = [];

        foreach ($this->subscribers->findActive() as $abonne) {
            $locale = Locale::tryFrom($abonne['locale']) ?? Locale::reference();
            $rows[] = [
                'email' => $abonne['email'],
                'langue' => $locale->value,
                'source' => $abonne['source'],
                'consentement' => $abonne['consented_at'],
                'texte' => $abonne['consent_text'],
                'desinscription' => $this->url->absolute('newsletter.unsubscribe', [
                    'locale' => $locale->value,
                    'email' => $abonne['email'],
                    'jeton' => $this->tokens->for($abonne['email']),
                ]),
            ];
        }

        return (new Response(CsvWriter::build(self::EXPORT_HEADERS, $rows), 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="newsletter.csv"')
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function unsubscribe(Request $request): Response
    {
        $email = (string) $request->input('email');

        if ($this->newsletter->unsubscribe($email)) {
            $this->chrome->audit()->record($this->chrome->currentUserId(), 'newsletter.unsubscribe', $request, 'newsletter', null);
        }

        return RedirectResponse::to($request->basePath . '/admin/newsletter');
    }
}
