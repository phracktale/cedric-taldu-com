<?php

declare(strict_types=1);

namespace APress\Core\Extension;

use JsonException;

/**
 * Contenu validé d'un `extension.json`.
 *
 * Le manifeste vient d'un fichier déposé sur le serveur, pas d'un visiteur ;
 * il est pourtant validé champ par champ, parce que ses valeurs deviennent des
 * chemins (autoload, migrations) et un nom de classe instanciée. Un nom
 * d'extension ne peut donc contenir ni point ni barre oblique, et un chemin
 * d'autoload doit rester sous le dossier de l'extension.
 */
final class Manifest
{
    public const FILE = 'extension.json';
    private const NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';
    private const NAMESPACE_PREFIX = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\$/';
    private const RELATIVE_DIR = '/^(?!\/)(?!.*(?:^|\/)\.\.(?:\/|$))[A-Za-z0-9_\-\/]+\/$/';

    /**
     * @param list<string> $requiredExtensions
     * @param array<string, string> $autoload préfixe de namespace => dossier absolu
     */
    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $description,
        public readonly string $version,
        public readonly string $coreConstraint,
        public readonly array $requiredExtensions,
        public readonly string $entry,
        public readonly array $autoload,
        public readonly string $directory,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw InvalidManifest::because($path, 'fichier illisible.');
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidManifest::because($path, 'JSON invalide (' . $exception->getMessage() . ').');
        }

        if (!is_array($data)) {
            throw InvalidManifest::because($path, 'un objet JSON est attendu.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data, dirname($path));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $directory): self
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $fail = static fn (string $reason): InvalidManifest => InvalidManifest::because($directory, $reason);

        $name = self::string($data, 'name');
        if ($name === null || preg_match(self::NAME, $name) !== 1) {
            throw $fail('« name » doit être un identifiant en minuscules (lettres, chiffres, tirets).');
        }
        if (basename($directory) !== $name) {
            throw $fail(sprintf('« name » (%s) doit être le nom du dossier (%s).', $name, basename($directory)));
        }

        $label = self::string($data, 'label');
        if ($label === null || trim($label) === '') {
            throw $fail('« label » est requis.');
        }

        $version = self::string($data, 'version');
        if ($version === null || !VersionConstraint::isValidVersion($version)) {
            throw $fail('« version » doit être au format SemVer x.y.z.');
        }

        $requires = $data['requires'] ?? null;
        $core = is_array($requires) ? ($requires['core'] ?? null) : null;
        if (!is_string($core) || !VersionConstraint::isValidConstraint($core)) {
            throw $fail('« requires.core » doit être une contrainte de version (^0.2, >=1.0…).');
        }

        $dependencies = is_array($requires) ? ($requires['extensions'] ?? []) : [];
        if (!is_array($dependencies) || !array_is_list($dependencies)) {
            throw $fail('« requires.extensions » doit être une liste de noms.');
        }
        $names = [];
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || preg_match(self::NAME, $dependency) !== 1) {
                throw $fail('« requires.extensions » contient un nom invalide.');
            }
            $names[] = $dependency;
        }
        $dependencies = $names;

        $entry = self::string($data, 'entry');
        if ($entry === null || preg_match(self::CLASS_NAME, $entry) !== 1) {
            throw $fail('« entry » doit être un nom de classe complet.');
        }

        $autoload = [];
        $rawAutoload = $data['autoload'] ?? [];
        if (!is_array($rawAutoload)) {
            throw $fail('« autoload » doit associer un préfixe de namespace à un dossier.');
        }
        foreach ($rawAutoload as $prefix => $path) {
            if (
                !is_string($prefix) || preg_match(self::NAMESPACE_PREFIX, $prefix) !== 1
                || !is_string($path) || preg_match(self::RELATIVE_DIR, $path) !== 1
            ) {
                throw $fail('« autoload » : préfixe ou dossier invalide (dossier relatif, sans « .. », finissant par /).');
            }
            $autoload[$prefix] = $directory . '/' . $path;
        }

        return new self(
            $name,
            $label,
            self::string($data, 'description') ?? '',
            $version,
            $core,
            $dependencies,
            $entry,
            $autoload,
            $directory,
        );
    }

    public function migrationsDirectory(): string
    {
        return $this->directory . '/migrations';
    }

    public function templatesDirectory(): string
    {
        return $this->directory . '/templates';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
