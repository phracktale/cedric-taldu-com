<?php

declare(strict_types=1);

namespace APress\Core\Theme;

use APress\Core\Extension\VersionConstraint;
use InvalidArgumentException;

/**
 * Thèmes disponibles : dossiers `<racine>/<type>/<nom>/theme.json`.
 *
 * Un thème invalide ou incompatible avec le cœur n'est jamais proposé ; la
 * raison est rendue par errors() pour la page d'administration. Le choix du
 * thème actif appartient au site (réglage en base) : le registre ne fait que
 * dire ce qui existe.
 */
final class ThemeRegistry
{
    private const NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @var array<string, array{themes: list<Theme>, errors: array<string, string>}> */
    private array $cache = [];

    public function __construct(
        private readonly string $directory,
        private readonly string $coreVersion,
    ) {
    }

    /**
     * @return list<Theme> triés par nom
     */
    public function all(ThemeType $type): array
    {
        return $this->scan($type)['themes'];
    }

    /**
     * @return array<string, string> nom du dossier => raison du refus
     */
    public function errors(ThemeType $type): array
    {
        return $this->scan($type)['errors'];
    }

    public function find(ThemeType $type, string $name): ?Theme
    {
        foreach ($this->all($type) as $theme) {
            if ($theme->name === $name) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * @return array{themes: list<Theme>, errors: array<string, string>}
     */
    private function scan(ThemeType $type): array
    {
        if (isset($this->cache[$type->value])) {
            return $this->cache[$type->value];
        }

        $root = $this->directory . '/' . $type->value;
        $entries = is_dir($root) ? (scandir($root) ?: []) : [];
        $themes = [];
        $errors = [];

        foreach ($entries as $entry) {
            if (preg_match(self::NAME, $entry) !== 1 || !is_file($root . '/' . $entry . '/' . Theme::FILE)) {
                continue;
            }

            try {
                $theme = Theme::fromFile($root . '/' . $entry . '/' . Theme::FILE);
            } catch (InvalidArgumentException $exception) {
                $errors[$entry] = $exception->getMessage();
                continue;
            }

            if ($theme->type !== $type) {
                $errors[$entry] = sprintf('Thème de type « %s » rangé parmi les thèmes « %s ».', $theme->type->value, $type->value);
                continue;
            }

            if (!VersionConstraint::satisfies($this->coreVersion, $theme->coreConstraint)) {
                $errors[$entry] = sprintf('Exige le cœur A-Press %s ; version installée : %s.', $theme->coreConstraint, $this->coreVersion);
                continue;
            }

            $themes[] = $theme;
        }

        usort($themes, static fn (Theme $a, Theme $b): int => strcmp($a->name, $b->name));
        ksort($errors);

        return $this->cache[$type->value] = ['themes' => $themes, 'errors' => $errors];
    }
}
