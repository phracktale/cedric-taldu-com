<?php

declare(strict_types=1);

namespace APress\Core\Theme;

use APress\Core\Exception\TemplateNotFound;

/**
 * Dossiers de gabarits, du plus prioritaire au moins prioritaire :
 * thème actif, puis extensions, puis gabarits du site.
 *
 * Un thème surcharge un gabarit en fournissant le même nom relatif
 * (`layouts/admin`). Les contrôles sont ceux qu'appliquait View sur un dossier
 * unique, répétés pour chaque dossier : nom en minuscules sans « .. » ni
 * extension, fichier réellement sous le dossier (realpath), et casse exacte —
 * sous Windows, « Front/Home » trouverait « front/home.php » puis casserait sur
 * le serveur Linux.
 */
final class TemplatePaths
{
    private const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/D';

    /** @var list<string> */
    private array $directories;

    /**
     * @param list<string> $directories
     */
    public function __construct(array $directories)
    {
        $this->directories = $directories;
    }

    /**
     * Copie avec un dossier ajouté en tête (le plus prioritaire).
     */
    public function prepend(string $directory): self
    {
        return new self([$directory, ...$this->directories]);
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->directories;
    }

    public function find(string $template): string
    {
        if (preg_match(self::NAME_PATTERN, $template) !== 1) {
            throw TemplateNotFound::forName($template);
        }

        foreach ($this->directories as $directory) {
            $root = realpath($directory);
            if ($root === false) {
                continue;
            }

            $expected = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $template) . '.php';
            $file = realpath($expected);

            if ($file !== false && $file === $expected && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
                return $file;
            }
        }

        throw TemplateNotFound::forName($template);
    }
}
