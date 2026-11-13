<?php

declare(strict_types=1);

namespace APress\Core\Extension;

use InvalidArgumentException;

/**
 * Contraintes de version SemVer, réduites à ce dont les manifestes ont besoin :
 * `^x.y[.z]`, `>=x.y[.z]`, `x.y.z` exact et `*`.
 *
 * Le caret suit Composer : `^1.2` accepte 1.x à partir de 1.2 ; en 0.x, la
 * mineure fait office de majeure (`^0.1` refuse 0.2), puisqu'avant 1.0 une
 * mineure peut casser la compatibilité.
 */
final class VersionConstraint
{
    private const VERSION = '/^(\d+)\.(\d+)\.(\d+)$/';
    private const PARTIAL = '(\d+)\.(\d+)(?:\.(\d+))?';

    public static function satisfies(string $version, string $constraint): bool
    {
        $actual = self::parseVersion($version);
        $constraint = trim($constraint);

        if ($constraint === '*') {
            return true;
        }

        if (preg_match('/^\^' . self::PARTIAL . '$/', $constraint, $m) === 1) {
            $min = self::fromMatch($m);
            $max = $min[0] > 0 ? [$min[0] + 1, 0, 0] : [0, $min[1] + 1, 0];

            return self::compare($actual, $min) >= 0 && self::compare($actual, $max) < 0;
        }

        if (preg_match('/^>=\s*' . self::PARTIAL . '$/', $constraint, $m) === 1) {
            return self::compare($actual, self::fromMatch($m)) >= 0;
        }

        if (preg_match(self::VERSION, $constraint) === 1) {
            return self::compare($actual, self::parseVersion($constraint)) === 0;
        }

        throw new InvalidArgumentException(sprintf('Contrainte de version illisible : « %s ».', $constraint));
    }

    public static function isValidVersion(string $version): bool
    {
        return preg_match(self::VERSION, $version) === 1;
    }

    public static function isValidConstraint(string $constraint): bool
    {
        try {
            self::satisfies('0.0.0', $constraint);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return array{int, int, int}
     */
    private static function parseVersion(string $version): array
    {
        if (preg_match(self::VERSION, $version, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Version illisible : « %s ».', $version));
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * @param array<int, string> $m
     * @return array{int, int, int}
     */
    private static function fromMatch(array $m): array
    {
        return [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     */
    private static function compare(array $a, array $b): int
    {
        return $a <=> $b;
    }
}
