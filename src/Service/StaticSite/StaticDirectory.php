<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Dossier du site statique (`public/static/site` par défaut, `STATIC_DIR` sinon).
 *
 * Une génération s'écrit dans un dossier de travail voisin, puis prend la
 * place de l'ancien par renommage : un visiteur ne voit jamais un site à
 * moitié écrit.
 */
final class StaticDirectory
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function workDirectory(string $suffix): string
    {
        $travail = $this->path . '.travail-' . $suffix;
        self::remove($travail);

        if (!mkdir($travail, 0775, true) && !is_dir($travail)) {
            throw new RuntimeException('Dossier de génération impossible à créer.');
        }

        return $travail;
    }

    public function write(string $workDirectory, string $relative, string $content): void
    {
        $fichier = $workDirectory . '/' . $relative;
        $dossier = dirname($fichier);

        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new RuntimeException('Dossier impossible à créer : ' . $relative);
        }

        if (file_put_contents($fichier, $content) === false) {
            throw new RuntimeException('Écriture impossible : ' . $relative);
        }
    }

    /**
     * Le dossier de travail devient le site statique.
     */
    public function publish(string $workDirectory): void
    {
        $ancien = $this->path . '.ancien';
        self::remove($ancien);

        if (is_dir($this->path)) {
            rename($this->path, $ancien);
        }

        rename($workDirectory, $this->path);
        self::remove($ancien);
    }

    public function clear(): void
    {
        self::remove($this->path);
    }

    public static function remove(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }

        $elements = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($elements as $element) {
            /** @var SplFileInfo $element */
            $element->isDir() ? rmdir($element->getPathname()) : unlink($element->getPathname());
        }

        rmdir($dossier);
    }
}
