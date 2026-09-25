<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

use App\Core\ClockInterface;
use App\Core\Kernel;
use App\Core\Request;
use App\Http\Middleware\SecurityHeaders;
use Closure;

/**
 * Génération statique du site public (retours du 2026-09-25, point 7).
 *
 * Chaque page du catalogue est rejouée par le noyau, comme une visite, avec
 * l'attribut `static_render` : le gabarit n'y met ni jeton CSRF ni pastille de
 * panier (etat.js les complète chez le visiteur), et le nonce est retiré. Le
 * `.htaccess` du dossier porte les en-têtes de sécurité, la CSP autorisant le
 * style en ligne par empreinte.
 *
 * Le résultat n'est publié que si aucune invalidation n'est survenue pendant
 * le rendu : une page rendue avant une vente ne remplace jamais la page à jour.
 */
final class Generator
{
    public const ATTRIBUTE = 'static_render';

    /**
     * @param Closure(): Kernel $kernel fourni à la demande : le noyau dépend
     *        lui-même, par ses contrôleurs, de ce générateur
     */
    public function __construct(
        private readonly Closure $kernel,
        private readonly PageCatalog $catalog,
        private readonly StaticDirectory $directory,
        private readonly GenerationStore $store,
        private readonly SecurityHeaders $headers,
        private readonly ClockInterface $clock,
    ) {
    }

    public function generate(Request $origin): GenerationState
    {
        $debut = $this->clock->now();
        $chrono = hrtime(true);
        $precedent = $this->store->load();
        $travail = $this->directory->workDirectory(bin2hex(random_bytes(4)));
        $kernel = ($this->kernel)();

        $journal = [];
        $empreintes = [];
        $pages = 0;

        foreach ($this->catalog->paths($origin->basePath) as $chemin) {
            $t0 = hrtime(true);
            $reponse = $kernel->handle(new Request(
                'GET',
                $chemin,
                $origin->basePath,
                [],
                [],
                [],
                ['host' => $origin->header('host') ?? 'localhost', 'accept' => 'text/html'],
                $origin->clientIp,
                $origin->secure,
                null,
                [self::ATTRIBUTE => '1'],
            ));

            $estPage = $reponse->status === 200
                && str_starts_with($reponse->header('Content-Type') ?? '', 'text/html');

            if ($estPage) {
                $html = (string) preg_replace('/ nonce="[^"]*"/', '', $reponse->body);
                foreach (self::inlineStyles($html) as $style) {
                    $empreintes['sha256-' . base64_encode(hash('sha256', $style, true))] = true;
                }
                $this->directory->write($travail, StaticPath::fileFor($chemin), $html);
                $pages++;
            }

            $journal[] = [
                'at' => $this->clock->now()->format('Y-m-d\TH:i:s.vP'),
                'path' => $origin->basePath . $chemin,
                'ms' => intdiv(hrtime(true) - $t0, 1_000_000),
                'status' => $reponse->status,
            ];
        }

        $this->directory->write($travail, '.htaccess', $this->htaccess(array_keys($empreintes)));

        // Invalidation survenue pendant le rendu : ces pages sont déjà vieilles.
        $actuel = $this->store->load();
        if ($actuel->invalidatedAt !== null && $actuel->invalidatedAt !== $precedent->invalidatedAt) {
            StaticDirectory::remove($travail);

            return $actuel;
        }

        $this->directory->publish($travail);

        $etat = new GenerationState(
            $precedent->number + 1,
            $debut->format('Y-m-d\TH:i:s.vP'),
            $pages,
            intdiv(hrtime(true) - $chrono, 1_000_000),
            false,
            $actuel->invalidatedAt,
            $journal,
        );
        $this->store->save($etat, $this->clock->now());

        return $etat;
    }

    /**
     * @return list<string>
     */
    private static function inlineStyles(string $html): array
    {
        preg_match_all('#<style>(.*?)</style>#s', $html, $blocs);

        return $blocs[1];
    }

    /**
     * @param list<string> $empreintes
     */
    private function htaccess(array $empreintes): string
    {
        $lignes = [
            '# Généré avec le site statique — ne pas modifier : réécrit à chaque génération.',
            '# En-têtes des pages servies par Apache seul (voir SecurityHeaders::staticHeaders).',
            'Options -Indexes',
            '<IfModule mod_headers.c>',
        ];

        foreach ($this->headers->staticHeaders($empreintes) as $nom => $valeur) {
            $lignes[] = '    Header always set ' . $nom . ' "' . str_replace('"', '', $valeur) . '"';
        }

        // Toujours revalidé : une invalidation doit se voir aussitôt.
        $lignes[] = '    Header set Cache-Control "no-cache"';
        $lignes[] = '</IfModule>';

        return implode("\n", $lignes) . "\n";
    }
}
