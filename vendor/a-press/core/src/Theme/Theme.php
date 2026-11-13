<?php

declare(strict_types=1);

namespace APress\Core\Theme;

use APress\Core\Extension\VersionConstraint;
use InvalidArgumentException;
use JsonException;

/**
 * Contenu validé d'un `theme.json`.
 *
 * ```json
 * {
 *     "name": "wp-like",
 *     "label": "Façon WordPress",
 *     "description": "…",
 *     "version": "1.0.0",
 *     "type": "admin",
 *     "requires": { "core": "^0.4" },
 *     "stylesheet": "css/themes/wp-like.css"
 * }
 * ```
 *
 * Les gabarits du thème sont dans son dossier `templates/`, hors webroot. La
 * feuille de style, elle, doit être servie par le serveur web : c'est un
 * chemin sous les assets publics du site, contraint à `css/themes/…`.
 */
final class Theme
{
    public const FILE = 'theme.json';
    private const NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const STYLESHEET = '/^css\/themes\/[a-z0-9]+(?:-[a-z0-9]+)*\.css$/';

    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $description,
        public readonly string $version,
        public readonly ThemeType $type,
        public readonly string $coreConstraint,
        public readonly ?string $stylesheet,
        public readonly string $directory,
    ) {
    }

    /**
     * @throws InvalidArgumentException manifeste illisible ou invalide (message sans chemin)
     */
    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidArgumentException('theme.json illisible.');
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('theme.json : JSON invalide.');
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('theme.json : un objet JSON est attendu.');
        }

        $directory = (string) realpath(dirname($path));
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME, $name) !== 1 || $name !== basename($directory)) {
            throw new InvalidArgumentException('« name » doit être le nom du dossier, en minuscules et tirets.');
        }

        $label = $data['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            throw new InvalidArgumentException('« label » est requis.');
        }

        $version = $data['version'] ?? null;
        if (!is_string($version) || !VersionConstraint::isValidVersion($version)) {
            throw new InvalidArgumentException('« version » doit être au format x.y.z.');
        }

        $type = ThemeType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '');
        if ($type === null) {
            throw new InvalidArgumentException('« type » doit valoir admin ou front.');
        }

        $requires = $data['requires'] ?? null;
        $core = is_array($requires) ? ($requires['core'] ?? null) : null;
        if (!is_string($core) || !VersionConstraint::isValidConstraint($core)) {
            throw new InvalidArgumentException('« requires.core » doit être une contrainte de version.');
        }

        $stylesheet = $data['stylesheet'] ?? null;
        if ($stylesheet !== null && (!is_string($stylesheet) || preg_match(self::STYLESHEET, $stylesheet) !== 1)) {
            throw new InvalidArgumentException('« stylesheet » doit être de la forme css/themes/<nom>.css.');
        }

        $description = $data['description'] ?? '';

        return new self(
            $name,
            $label,
            is_string($description) ? $description : '',
            $version,
            $type,
            $core,
            $stylesheet,
            $directory,
        );
    }

    public function templatesDirectory(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'templates';
    }
}
