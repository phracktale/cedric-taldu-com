<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Locale;
use App\Service\Newsletter\Newsletter;
use App\Service\Newsletter\UnsubscribeToken;
use App\Service\View\Chrome;

/**
 * Désinscription de la newsletter par lien signé (revue du 2026-09-24).
 *
 * Le lien (GET) ne fait qu'afficher une confirmation : un robot qui suit les
 * liens d'un e-mail ne désinscrit personne. La désinscription est un POST
 * protégé par jeton CSRF. Un lien invalide répond 400 sans rien révéler.
 */
final class NewsletterController
{
    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly Newsletter $newsletter,
        private readonly UnsubscribeToken $tokens,
    ) {
    }

    public function confirm(Request $request): Response
    {
        $email = (string) $request->query('email');
        $token = (string) $request->query('jeton');

        if (!$this->tokens->verify($email, $token)) {
            return $this->page($request, 'invalid', 400);
        }

        return $this->page($request, 'confirm', 200, $email, $token);
    }

    public function unsubscribe(Request $request): Response
    {
        $email = (string) $request->input('email');

        if (!$this->tokens->verify($email, (string) $request->input('jeton'))) {
            return $this->page($request, 'invalid', 400);
        }

        $this->newsletter->unsubscribe($email);

        return $this->page($request, 'done', 200);
    }

    private function page(Request $request, string $state, int $status, string $email = '', string $token = ''): Response
    {
        $locale = Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);

        return Response::html($this->view->render('front/newsletter-unsubscribe', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $locale === Locale::Fr ? 'Newsletter' : 'Newsletter',
            'state' => $state,
            'email' => $email,
            'token' => $token,
        ], layout: 'layouts/public'), $status);
    }
}
