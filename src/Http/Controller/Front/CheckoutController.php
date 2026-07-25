<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\CookieFactory;
use App\Core\Exception\NotFoundException;
use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Exception\InvalidAddress;
use App\Domain\Locale;
use App\Domain\Order\Address;
use App\Domain\Order\OrderReference;
use App\Domain\Shipping\ShippingMethod;
use App\Domain\Shop\Cart;
use App\Domain\Shop\PricingPolicy;
use App\Repository\CartRepository;
use App\Repository\OrderRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\Payment\CheckoutOutcome;
use App\Service\Payment\CheckoutRequest;
use App\Service\Payment\CheckoutService;
use App\Service\View\Chrome;
use DateTimeImmutable;

/**
 * Tunnel de commande (03-boutique §3).
 *
 * Trois responsabilites : afficher le formulaire, le traiter, puis rendre la
 * page de retour apres paiement. Le controleur reste mince : tout le calcul —
 * revalidation, port, TVA, creation, reservation, session Stripe — vit dans
 * CheckoutService, en une transaction.
 *
 * Le controleur ne lit AUCUN montant du formulaire (03-boutique §8.1) : il n'en
 * recueille que l'identite, le mode de remise et l'adresse.
 */
final class CheckoutController
{
    private const COOKIE = CookieFactory::PREFIX . 'cart';

    /**
     * Champ appat : un robot le remplit, un humain ne le voit pas
     * (06-securite §6.1). Rempli -> rejet silencieux avec une reponse
     * d'apparence normale, pour ne pas informer le robot.
     */
    private const HONEYPOT = 'site_web';

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly CartRepository $carts,
        private readonly OrderRepository $orders,
        private readonly CheckoutService $checkout,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function form(Request $request): Response
    {
        $locale = self::locale($request);
        $cart = $this->cart($request, $locale);

        $valuation = PricingPolicy::value($cart, $this->carts->catalogueFor($cart, new DateTimeImmutable()));

        // Un panier vide n'a rien a commander : on renvoie au panier plutot que
        // d'afficher un formulaire sans objet.
        if ($valuation->isEmpty()) {
            return $this->toCart($locale);
        }

        return Response::html($this->view->render('front/checkout', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => 'Commande',
            'localeSwitch' => $this->url->localeAlternates('checkout.form'),
            'valuation' => $valuation,
            'submitUrl' => $this->url->route('checkout.submit', ['locale' => $locale->value]),
            'honeypot' => self::HONEYPOT,
        ], layout: 'layouts/public'));
    }

    public function submit(Request $request): Response
    {
        $locale = self::locale($request);

        // Honeypot : reponse d'apparence normale, aucune commande creee. On ne
        // dit pas au robot pourquoi rien ne s'est passe.
        if (($request->input(self::HONEYPOT) ?? '') !== '') {
            $this->logger->log(LogLevel::Warning, 'Commande rejetée (honeypot)', []);

            return $this->toCart($locale);
        }

        $method = $request->input('mode') === 'pickup' ? ShippingMethod::Pickup : ShippingMethod::Shipping;

        // Case CGV obligatoire (03-boutique §3, étape 2).
        if ($request->input('cgv') === null) {
            return $this->rejectForm($request, $locale, 'Vous devez accepter les conditions générales de vente.');
        }

        try {
            $checkoutRequest = $this->buildRequest($request, $locale, $method);
        } catch (InvalidAddress $e) {
            return $this->rejectForm($request, $locale, 'Adresse de livraison incomplète ou invalide.');
        }

        $cart = $this->cart($request, $locale);
        $result = $this->checkout->checkout($cart, $checkoutRequest, new DateTimeImmutable());

        return match ($result->outcome) {
            // 303 vers Stripe : la seule issue qui quitte le site. L'URL vient
            // de la passerelle et son hote est verifie (RedirectResponse).
            CheckoutOutcome::Redirect => RedirectResponse::toExternal((string) $result->redirectUrl, 303),
            // Le panier a change ou ne couvre pas l'expedition : on y renvoie,
            // les messages seront affiches a l'arrivee.
            CheckoutOutcome::CartChanged,
            CheckoutOutcome::EmptyCart,
            CheckoutOutcome::ShippingOnRequest => $this->toCart($locale),
        };
    }

    public function confirmation(Request $request): Response
    {
        $locale = self::locale($request);
        $reference = $request->attribute('reference') ?? '';
        $token = (string) $request->query('t');

        // La reference vient de l'URL : findByReferenceAndToken la valide et
        // compare le jeton EN TEMPS CONSTANT. Sans jeton correct, la commande
        // reste introuvable — jamais un 403 qui confirmerait son existence.
        $order = $this->orders->findByReferenceAndToken($reference, $token);

        if ($order === null) {
            throw new NotFoundException('Commande introuvable.');
        }

        return Response::html($this->view->render('front/confirmation', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => 'Confirmation de commande',
            'order' => $order,
        ], layout: 'layouts/public'))
            // La page lit un etat qui evolue (le webhook peut suivre) : jamais
            // en cache, et jamais indexee.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // ------------------------------------------------------------ assistance

    private function buildRequest(Request $request, Locale $locale, ShippingMethod $method): CheckoutRequest
    {
        $address = null;

        // La remise en main propre n'exige aucune adresse (03-boutique §4) :
        // on n'en construit pas, donc on n'en collecte pas (06-securite §9).
        if ($method->requiresAddress()) {
            $address = new Address(
                (string) $request->input('adresse'),
                self::optional($request->input('complement')),
                (string) $request->input('code_postal'),
                (string) $request->input('ville'),
                (string) ($request->input('pays') ?? 'FR'),
            );
        }

        // Address valide deja les CR/LF ; l'e-mail est confie a CheckoutService
        // via l'adresse ci-dessous. Le nom et l'e-mail sont nettoyes ici.
        $email = trim((string) $request->input('email'));

        if (strpbrk($email, "\r\n") !== false || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidAddress('E-mail invalide.');
        }

        return new CheckoutRequest(
            locale: $locale,
            customerName: self::header((string) $request->input('nom')),
            customerEmail: $email,
            customerPhone: self::optional($request->input('telephone')),
            shippingMethod: $method,
            shippingAddress: $address,
            billingAddress: null,
            customerNote: self::note($request->input('note')),
        );
    }

    private function rejectForm(Request $request, Locale $locale, string $message): Response
    {
        $cart = $this->cart($request, $locale);
        $valuation = PricingPolicy::value($cart, $this->carts->catalogueFor($cart, new DateTimeImmutable()));

        return Response::html($this->view->render('front/checkout', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => 'Commande',
            'localeSwitch' => $this->url->localeAlternates('checkout.form'),
            'valuation' => $valuation,
            'submitUrl' => $this->url->route('checkout.submit', ['locale' => $locale->value]),
            'honeypot' => self::HONEYPOT,
            'error' => $message,
        ], layout: 'layouts/public'), 422);
    }

    private function cart(Request $request, Locale $locale): Cart
    {
        return $this->carts->open($request->cookie(self::COOKIE), $locale);
    }

    private function toCart(Locale $locale): Response
    {
        return RedirectResponse::to($this->url->route('cart.show', ['locale' => $locale->value]), 303);
    }

    private static function optional(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : self::header($value);
    }

    /** Un champ d'en-tete ne doit pas transporter de saut de ligne dans un e-mail. */
    private static function header(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    private static function note(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Champ de note borne a 500 caracteres (03-boutique §3, étape 2). Les
        // CR/LF sont admis dans le corps ; c'est un texte libre, pas un en-tete.
        return mb_substr(str_replace("\0", '', $value), 0, 500);
    }

    private static function locale(Request $request): Locale
    {
        return Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
    }
}
