<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Exception\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repository\FulfillmentRepository;
use App\Service\Fulfillment\PrintAssetStore;
use App\Service\Fulfillment\PrintAssetUrl;

/**
 * Sert le fichier d'impression d'une œuvre au robot de Prodigi.
 *
 * Le fichier vit HORS webroot ; cette route à jeton signé est le seul moyen d'y
 * accéder, en lecture, par une URL publique. Un jeton invalide, une œuvre sans
 * fichier ou un fichier absent rendent 404 — jamais un indice sur ce qui existe.
 *
 * Aucune énumération possible : le jeton porte une signature HMAC (PrintAssetUrl)
 * qu'on ne peut pas forger sans le secret.
 */
final class PrintAssetController
{
    public function __construct(
        private readonly PrintAssetUrl $urls,
        private readonly FulfillmentRepository $fulfillment,
        private readonly PrintAssetStore $store,
    ) {
    }

    public function serve(Request $request): Response
    {
        $artworkId = $this->urls->verify((string) $request->attribute('token'));

        if ($artworkId === null) {
            throw new NotFoundException('Fichier introuvable.');
        }

        $asset = $this->fulfillment->printAssetOf($artworkId);

        if ($asset === null) {
            throw new NotFoundException('Fichier introuvable.');
        }

        $absolute = $this->store->absolutePathFor($asset['path']);
        $bytes = is_file($absolute) ? file_get_contents($absolute) : false;

        if ($bytes === false) {
            throw new NotFoundException('Fichier introuvable.');
        }

        // Type réel stocké, sans reniflage, jamais indexé, jamais en cache.
        return (new Response($bytes, 200))
            ->withHeader('Content-Type', $asset['mime'])
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
