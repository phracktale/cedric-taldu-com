<?php

/**
 * Verification syntaxique recursive (`php -l`) de tout le PHP du depot.
 *
 * Ecrit en PHP et non en shell : `composer lint` doit tourner a l'identique sous
 * Windows et sous Linux (09-environnements §5).
 *
 * Usage : php bin/lint.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['bin', 'public', 'src', 'templates', 'tests'];

$binary = PHP_BINARY;
$files = 0;
$failures = [];

foreach ($directories as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $files++;
        $output = [];
        $status = 0;
        exec(
            escapeshellarg($binary) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1',
            $output,
            $status
        );

        if ($status !== 0) {
            $failures[] = implode(PHP_EOL, $output);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    fwrite(STDERR, sprintf('%d fichier(s) en erreur sur %d analyses.' . PHP_EOL, count($failures), $files));
    exit(1);
}

fwrite(STDOUT, sprintf('Syntaxe correcte : %d fichiers analyses.' . PHP_EOL, $files));
exit(0);
