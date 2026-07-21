<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Core\ClockInterface;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Http\Middleware\SecurityHeaders;
use App\Service\Auth\AdminSession;
use App\Service\Auth\AuditTrail;
use DateTimeImmutable;

/**
 * Contexte commun a toutes les pages du back-office.
 *
 * Pendant de Chrome pour le front, avec trois differences qui tiennent au fait
 * qu'il s'agit d'une interface privee :
 *
 *  - `Cache-Control: no-store` sur TOUTE reponse. Une page d'administration en
 *    cache partage, c'est un jeton CSRF servi a plusieurs visiteurs et une fiche
 *    de commande lisible apres deconnexion.
 *  - Le jeton CSRF est fourni au gabarit : chaque formulaire le porte.
 *  - L'interface est en francais seulement (04-back-office, en-tete) : aucune
 *    negociation de langue, aucun hreflang.
 */
final class AdminChrome
{
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly AdminSession $session,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function page(Request $request, string $template, array $data = [], int $status = 200): Response
    {
        $user = $this->session->currentUser();

        $html = $this->view->render($template, [
            'nonce' => $request->attribute(SecurityHeaders::NONCE_ATTRIBUTE) ?? '',
            'basePath' => $request->basePath,
            'env' => $this->config->env,
            'isProduction' => $this->config->isProduction(),
            'csrfToken' => $this->csrf->token(),
            'utilisateur' => $user,
            'titre' => 'Administration',
            'chemin' => $request->path,
            ...$data,
        ], layout: 'layouts/admin');

        return Response::html($html, $status)
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            // 09-environnements §7 : le back-office n'est jamais indexe, quel
            // que soit l'environnement — la preprod comme la production.
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function audit(): AuditTrail
    {
        return $this->audit;
    }

    /**
     * Acteur a inscrire au journal d'audit.
     *
     * Null est possible en theorie — une action d'administration sans session —
     * mais AuthGuard l'exclut sur toute route fermee. Le journal accepte
     * l'absence d'acteur plutot que de refuser la trace : mieux vaut savoir
     * qu'une action a eu lieu sans savoir qui, que de ne rien savoir.
     */
    public function currentUserId(): ?int
    {
        return $this->session->currentUser()?->id;
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
