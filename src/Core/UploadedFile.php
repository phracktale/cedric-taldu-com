<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fichier televerse, tel que PHP le presente.
 *
 * Dans Core et non dans Service : c'est le pendant de Cookie ou de Request pour
 * `$_FILES`, et src/CLAUDE.md interdit de lire cette superglobale ailleurs que
 * dans Request — qui construit ces objets.
 *
 * L'objet ne valide RIEN : ni type, ni taille, ni contenu. Il transporte ce que
 * PHP a recu, y compris le nom de fichier choisi par le client, dont
 * 06-securite §5.5 rappelle qu'il n'est qu'une metadonnee a afficher, jamais un
 * chemin.
 */
final class UploadedFile
{
    public function __construct(
        /** Nom envoye par le CLIENT. Jamais employe pour nommer un fichier. */
        public readonly string $clientName,
        /** Chemin temporaire choisi par PHP. */
        public readonly string $path,
        public readonly int $size,
        /** Constante UPLOAD_ERR_*. */
        public readonly int $error = UPLOAD_ERR_OK,
    ) {
    }

    /**
     * Construit depuis une entree de $_FILES, ou null si la forme est
     * inattendue.
     *
     * @param array<string, mixed> $entry
     */
    public static function fromPhpUpload(array $entry): ?self
    {
        // Les televersements multiples donnent des TABLEAUX dans chaque clef :
        // ils se traitent par fromPhpUploads(), pas ici.
        if (!is_string($entry['tmp_name'] ?? null) || !is_string($entry['name'] ?? null)) {
            return null;
        }

        return new self(
            clientName: $entry['name'],
            path: $entry['tmp_name'],
            size: is_int($entry['size'] ?? null) ? $entry['size'] : 0,
            error: is_int($entry['error'] ?? null) ? $entry['error'] : UPLOAD_ERR_NO_FILE,
        );
    }

    /**
     * Aplatit une entree de $_FILES a champ multiple (`name="fichiers[]"`), ou
     * PHP transpose la structure : `['name' => [...], 'tmp_name' => [...]]`.
     *
     * @param array<string, mixed> $entry
     * @return list<self>
     */
    public static function fromPhpUploads(array $entry): array
    {
        if (!is_array($entry['tmp_name'] ?? null)) {
            $single = self::fromPhpUpload($entry);

            // Un champ laisse vide est une ABSENCE, pas un fichier a refuser :
            // l'appelant verifie « y a-t-il un fichier ? » et non « ce fichier
            // est-il present ? ». Un depassement de upload_max_filesize, lui,
            // porte un autre code et survit a ce filtre — il doit etre signale.
            return $single === null || $single->isMissing() ? [] : [$single];
        }

        $files = [];

        foreach (array_keys($entry['tmp_name']) as $index) {
            $file = self::fromPhpUpload([
                'name' => self::at($entry, 'name', $index),
                'tmp_name' => self::at($entry, 'tmp_name', $index),
                'size' => self::at($entry, 'size', $index),
                'error' => self::at($entry, 'error', $index),
            ]);

            if ($file !== null && !$file->isMissing()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    public function isMissing(): bool
    {
        return $this->error === UPLOAD_ERR_NO_FILE;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function at(array $entry, string $key, int|string $index): mixed
    {
        $values = $entry[$key] ?? null;

        return is_array($values) ? ($values[$index] ?? null) : null;
    }
}
