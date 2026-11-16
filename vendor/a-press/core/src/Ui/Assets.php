<?php

declare(strict_types=1);

namespace APress\Core\Ui;

use RuntimeException;

/**
 * Copie les fichiers statiques du cœur (feuille de style, script de
 * l'administration) sous <public>/assets/apress/, où le serveur web les sert
 * sans PHP. À lancer après chaque mise à jour du cœur (bin/apress-assets).
 */
final class Assets
{
    /**
     * @return list<string> fichiers copiés
     */
    public static function publish(string $publicDirectory): array
    {
        $source = dirname(__DIR__, 2) . '/assets';
        $target = rtrim($publicDirectory, '/\\') . '/assets/apress';

        if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
            throw new RuntimeException('Impossible de créer ' . $target);
        }

        $copied = [];
        foreach ([...(glob($source . '/*.css') ?: []), ...(glob($source . '/*.js') ?: [])] as $file) {
            $name = basename($file);
            if (!copy($file, $target . '/' . $name)) {
                throw new RuntimeException('Copie impossible : ' . $name);
            }
            $copied[] = $name;
        }

        sort($copied);

        return $copied;
    }
}
