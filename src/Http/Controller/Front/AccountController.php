<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Exception\NotFoundException;
use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Locale;
use App\Repository\NewsletterRepository;
use App\Repository\OrderRepository;
use App\Service\Account\CustomerLogin;
use App\Service\Account\CustomerSession;
use App\Service\I18n\UrlGenerator;
use App\Service\Mail\AccountMailer;
use App\Service\Newsletter\Newsletter;
use App\Service\Spam\Throttle;
use App\Service\View\Chrome;
use Throwable;

/**
 * Espace client (revue du 2026-09-24) : historique et détail des commandes,
 * factures, abonnement à la newsletter.
 *
 * Connexion sans mot de passe : l'acheteur demande un lien, envoyé à l'adresse
 * de ses commandes. La réponse est la MÊME que l'adresse soit connue ou non
 * (pas d'énumération) ; seule une adresse qui a commandé reçoit un courriel.
 * Les demandes sont limitées par adresse IP. Une commande d'un autre client
 * répond 404, jamais 403.
 */
final class AccountController
{
    /** Demandes de lien par IP et par quart d'heure. */
    private const LIMIT = 5;
    private const WINDOW = 900;

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly CustomerSession $session,
        private readonly CustomerLogin $login,
        private readonly OrderRepository $orders,
        private readonly NewsletterRepository $subscribers,
        private readonly Newsletter $newsletter,
        private readonly AccountMailer $mailer,
        private readonly Throttle $throttle,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $locale = self::locale($request);
        $email = $this->session->email();

        if ($email === null) {
            return $this->render($request, 'front/account/login', []);
        }

        return $this->render($request, 'front/account/index', [
            'email' => $email,
            'orders' => $this->orders->findForCustomer($email),
            'subscribed' => $this->subscribers->isActive($email),
            'orderUrl' => fn (string $reference): string => $this->url->route(
                'account.order',
                ['locale' => $locale->value, 'reference' => $reference],
            ),
        ]);
    }

    public function requestLink(Request $request): Response
    {
        $locale = self::locale($request);

        if (!$this->throttle->allow('account.login', $request->clientIp, self::LIMIT, self::WINDOW)) {
            return $this->render($request, 'front/account/login', ['throttled' => true], 429);
        }

        $email = trim((string) $request->input('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false && $this->orders->hasOrders($email)) {
            $link = $this->url->absolute('account.login', [
                'locale' => $locale->value,
                'token' => $this->login->issue($email),
            ]);

            try {
                $this->mailer->sendLoginLink($email, $locale, $link);
            } catch (Throwable $exception) {
                $this->logger->log(LogLevel::Error, 'Lien de connexion non envoyé', ['exception' => $exception::class]);
            }
        }

        return $this->render($request, 'front/account/login', ['requested' => true]);
    }

    public function signIn(Request $request): Response
    {
        $email = $this->login->consume((string) $request->attribute('token'));

        if ($email === null) {
            return $this->render($request, 'front/account/login', ['invalidLink' => true], 400);
        }

        $this->session->login($email);

        return RedirectResponse::to($this->url->route('account.index', ['locale' => self::locale($request)->value]), 303);
    }

    public function signOut(Request $request): Response
    {
        $this->session->logout();

        return RedirectResponse::to($this->url->route('account.index', ['locale' => self::locale($request)->value]), 303);
    }

    public function order(Request $request): Response
    {
        $email = $this->session->email();

        if ($email === null) {
            return RedirectResponse::to($this->url->route('account.index', ['locale' => self::locale($request)->value]));
        }

        $order = $this->ownOrder($email, (string) $request->attribute('reference'));

        return $this->render($request, 'front/account/order', ['order' => $order]);
    }

    public function newsletter(Request $request): Response
    {
        $locale = self::locale($request);
        $email = $this->session->email();

        if ($email !== null) {
            if ($request->input('abonnement') !== null) {
                $this->newsletter->subscribe($email, $locale, Newsletter::SOURCE_ACCOUNT);
            } else {
                $this->newsletter->unsubscribe($email);
            }
        }

        return RedirectResponse::to($this->url->route('account.index', ['locale' => $locale->value]), 303);
    }

    /**
     * Commande du client connecté, ou 404 (jamais 403 : pas d'énumération).
     */
    public function ownOrder(string $email, string $reference): \App\Repository\PersistedOrder
    {
        foreach ($this->orders->findForCustomer($email) as $order) {
            if ($order->reference === $reference) {
                return $order;
            }
        }

        throw new NotFoundException('Commande introuvable.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(Request $request, string $template, array $data, int $status = 200): Response
    {
        $locale = self::locale($request);

        return Response::html($this->view->render($template, [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $locale === Locale::Fr ? 'Mon compte' : 'My account',
            ...$data,
        ], layout: 'layouts/public'), $status)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function locale(Request $request): Locale
    {
        return Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
    }
}
