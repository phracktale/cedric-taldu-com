<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Une entree de la table de routes.
 *
 * Il n'y a aucune decouverte automatique : toutes les routes sont ecrites a la
 * main dans config/routes.php et se lisent d'un coup d'œil. Les tests de securite
 * parcourent cette table pour verifier que chaque route non-GET est protegee par
 * un jeton CSRF (06-securite §3).
 *
 * Les segments sont propres a chaque langue (« /fr/oeuvre/{slug} » contre
 * « /en/artwork/{slug} ») : c'est le couple nom + langue qui identifie une route.
 */
final class Route
{
    /** Slug de contenu : minuscules, chiffres, tirets simples (src/CLAUDE.md). */
    public const SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** Identifiant numerique strictement positif. */
    public const ID = '[1-9][0-9]*';

    /** A defaut de contrainte declaree, un parametre ne franchit pas un « / ». */
    private const DEFAULT_REQUIREMENT = '[^/]+';

    public readonly string $method;

    /** @var array{0: class-string, 1: string} */
    public readonly array $handler;

    /**
     * @param array{0: class-string, 1: string} $handler controleur et methode
     * @param array<string, string> $requirements expression reguliere par parametre
     * @param bool $guest route d'administration ouverte sans session : la
     *        connexion elle-meme, et rien d'autre. AuthTest parcourt la table et
     *        exige qu'une route sous /admin non marquee ainsi soit fermee.
     */
    public function __construct(
        public readonly string $name,
        string $method,
        public readonly string $path,
        array $handler,
        public readonly ?string $locale = null,
        public readonly array $requirements = [],
        public readonly bool $csrfExempt = false,
        public readonly bool $guest = false,
    ) {
        $this->method = strtoupper($method);
        $this->handler = $handler;
    }

    /**
     * La route appartient-elle au back-office ?
     *
     * Comparaison sur une frontiere de segment : « /administration » commence
     * bien par « /admin » sans etre sous ce prefixe.
     */
    public function isAdmin(): bool
    {
        return $this->path === '/admin' || str_starts_with($this->path, '/admin/');
    }

    /**
     * Expression reguliere complete du chemin.
     *
     * Le modificateur D est indispensable : sans lui, « $ » accepte un saut de
     * ligne final et « /fr/\n » correspondrait a la route « /fr/ ».
     */
    public function pattern(): string
    {
        $regex = '';

        foreach ($this->split() as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $matches) === 1) {
                $name = $matches[1];
                $requirement = $this->requirements[$name] ?? self::DEFAULT_REQUIREMENT;
                $regex .= '(?P<' . $name . '>' . $requirement . ')';
                continue;
            }

            $regex .= preg_quote($part, '#');
        }

        return '#^' . $regex . '$#D';
    }

    /**
     * @return list<string>
     */
    public function parameterNames(): array
    {
        $names = [];

        foreach ($this->split() as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $matches) === 1) {
                $names[] = $matches[1];
            }
        }

        return $names;
    }

    /**
     * Decoupe le chemin en alternant litteraux et parametres.
     *
     * @return list<string>
     */
    private function split(): array
    {
        $parts = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/',
            $this->path,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        return $parts === false ? [$this->path] : $parts;
    }
}
