<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\HttpException;
use App\Core\Exception\MethodNotAllowedException;
use Throwable;

/**
 * Transforme une exception en reponse HTTP presentable.
 *
 * 06-securite §10 : en production, `display_errors=0` et la page 500 affiche un
 * identifiant de correlation et RIEN d'autre — ni message d'exception, ni trace,
 * ni requete SQL, ni chemin serveur. L'exploitant retrouve le detail dans le
 * journal grace a cet identifiant.
 *
 * Les erreurs 4xx ne sont pas journalisees en erreur : un robot qui balaie des
 * chemins produirait un bruit qui masquerait les vrais incidents.
 */
final class ErrorResponder
{
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function render(Throwable $exception, Request $request): Response
    {
        if ($exception instanceof HttpException) {
            return $this->httpError($exception, $request);
        }

        $correlationId = $this->logger->log(LogLevel::Error, $exception->getMessage(), [
            'exception' => $exception::class,
            'fichier' => $exception->getFile() . ':' . $exception->getLine(),
            'chemin' => $request->path,
            'methode' => $request->method,
        ]);

        return $this->page(
            status: 500,
            request: $request,
            titre: 'Une erreur est survenue',
            message: 'Le site a rencontré un problème. Il a été enregistré et sera examiné.',
            correlationId: $correlationId,
            // Le detail n'apparait qu'en developpement, ou debug est autorise.
            // Config force debug a false des que APP_ENV vaut prod.
            detail: $this->config->debug
                ? $exception::class . ' : ' . $exception->getMessage()
                : null,
        );
    }

    private function httpError(HttpException $exception, Request $request): Response
    {
        $response = $this->page(
            status: $exception->statusCode(),
            request: $request,
            titre: self::title($exception->statusCode()),
            message: self::message($exception->statusCode()),
        );

        if ($exception instanceof MethodNotAllowedException) {
            $response = $response->withHeader('Allow', implode(', ', $exception->allowedMethods()));
        }

        return $response;
    }

    private function page(
        int $status,
        Request $request,
        string $titre,
        string $message,
        ?string $correlationId = null,
        ?string $detail = null,
    ): Response {
        $html = $this->view->render('error', [
            'locale' => $request->attribute('locale') ?? $this->config->defaultLocale,
            'nonce' => $request->attribute('csp_nonce') ?? '',
            'statut' => $status,
            'titre' => $titre,
            'message' => $message,
            'correlationId' => $correlationId,
            'detail' => $detail,
        ]);

        return Response::html($html, $status);
    }

    private static function title(int $status): string
    {
        return match ($status) {
            400 => 'Requête invalide',
            404 => 'Page introuvable',
            405 => 'Méthode non autorisée',
            419 => 'Formulaire expiré',
            default => 'Erreur',
        };
    }

    private static function message(int $status): string
    {
        return match ($status) {
            404 => 'Cette page n’existe pas, ou n’existe plus.',
            405 => 'Cette adresse n’accepte pas ce type de requête.',
            419 => 'Votre formulaire a expiré. Rechargez la page et recommencez.',
            default => 'La requête n’a pas pu être traitée.',
        };
    }
}
