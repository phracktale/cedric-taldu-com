<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\HttpException;
use App\Http\Middleware\MiddlewareInterface;
use LogicException;
use Throwable;

/**
 * Assemble une requete en reponse.
 *
 * C'est le point d'entree teste par tous les tests fonctionnels : aucun test ne
 * demarre de serveur HTTP (07-tests-tdd §1). public/index.php ne fait qu'appeler
 * handle() puis send().
 *
 * La route est resolue AVANT la chaine de middlewares, pour deux raisons :
 * CsrfGuard doit savoir si la route est exemptee, et une 404 doit malgre tout
 * traverser toute la chaine afin de porter les en-tetes de securite. L'echec de
 * routage est donc conserve et relance a l'interieur de la chaine.
 */
final class Kernel
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly ErrorResponder $errors,
        private readonly array $middlewares,
    ) {
    }

    public function handle(Request $request): Response
    {
        $match = null;
        $routingFailure = null;

        try {
            $match = $this->router->match($request);
        } catch (HttpException $exception) {
            $routingFailure = $exception;
        }

        if ($match !== null) {
            $request = $request->withAttributes([
                ...$match->parameters,
                ...($match->route->locale !== null ? ['locale' => $match->route->locale] : []),
            ]);
        }

        $handler = function (Request $request) use ($match, $routingFailure): Response {
            try {
                if ($routingFailure !== null) {
                    throw $routingFailure;
                }

                return $this->dispatch($request, $match);
            } catch (Throwable $exception) {
                // Converti ici, et non plus haut : la reponse d'erreur doit
                // ressortir par la chaine de middlewares pour recevoir les
                // en-tetes de securite comme n'importe quelle autre reponse.
                return $this->errors->render($exception, $request);
            }
        };

        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = $handler;
            $handler = static fn (Request $request): Response => $middleware->process($request, $match, $next);
        }

        try {
            return $handler($request);
        } catch (Throwable $exception) {
            // Filet de securite : une exception levee par un middleware lui-meme.
            return $this->errors->render($exception, $request);
        }
    }

    private function dispatch(Request $request, ?RouteMatch $match): Response
    {
        if ($match === null) {
            throw new LogicException('Aucune route à exécuter.');
        }

        [$class, $method] = $match->route->handler;
        $controller = $this->container->get($class);

        if (!method_exists($controller, $method)) {
            throw new LogicException(sprintf('Le contrôleur %s n\'a pas de méthode %s().', $class, $method));
        }

        /** @var mixed $response */
        $response = $controller->{$method}($request);

        if (!$response instanceof Response) {
            throw new LogicException(sprintf('%s::%s() doit retourner une Response.', $class, $method));
        }

        return $response;
    }
}
