<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Lecture du code source pour les tests de securite.
 *
 * Toute l'analyse passe par le tokeniseur de PHP et jamais par une simple
 * recherche de texte : un commentaire qui EXPLIQUE pourquoi `mt_rand` est
 * interdit ne doit pas etre compte comme un usage de `mt_rand`. Sans cela, le
 * seul moyen de faire passer la suite serait de retirer les commentaires qui
 * documentent les regles.
 */
final class SourceScanner
{
    /**
     * @return array<string, string> chemin relatif a la racine du depot => source
     */
    public static function files(string $directory, string $extension = 'php'): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];

        if (!is_dir($root . '/' . $directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== $extension) {
                continue;
            }

            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $sources[$path] = (string) file_get_contents($file->getPathname());
        }

        ksort($sources);

        return $sources;
    }

    /**
     * Source privee de ses commentaires.
     */
    public static function withoutComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }

    /**
     * Litteraux de chaine, indexes par numero de ligne.
     *
     * @return array<int, string>
     */
    public static function stringLiterals(string $source): array
    {
        $literals = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literals[$token[2]] = $token[1];
            }
        }

        return $literals;
    }
}
