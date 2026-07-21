<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Journal fichier, une ligne par entree, un fichier par jour.
 *
 * Deux exigences se rejoignent ici :
 *
 *  - la rotation quotidienne et la conservation limitee (09-environnements §8) ;
 *  - l'impossibilite de forger une fausse entree. Un message contenant un saut
 *    de ligne permettrait d'ecrire « connexion réussie » sous une ligne
 *    d'echec : tous les caracteres de controle sont donc echappes.
 */
final class FileLogger implements LoggerInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly ClockInterface $clock,
        private readonly RandomInterface $random = new SecureRandom(),
    ) {
    }

    /**
     * @param array<string, string|int|float|bool|null> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): string
    {
        $now = $this->clock->now();
        $correlationId = $this->random->hex(8);

        $line = sprintf(
            '%s %s [%s] %s %s',
            $now->format('c'),
            $level->value,
            $correlationId,
            self::singleLine($message),
            self::encodeContext($context),
        );

        $this->write($now->format('Y-m-d'), $line);

        return $correlationId;
    }

    private function write(string $day, string $line): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o770, true);
        }

        file_put_contents(
            $this->directory . '/app-' . $day . '.log',
            $line . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * @param array<string, string|int|float|bool|null> $context
     */
    private static function encodeContext(array $context): string
    {
        if ($context === []) {
            return '{}';
        }

        $clean = [];
        foreach ($context as $key => $value) {
            $clean[self::singleLine($key)] = is_string($value) ? self::singleLine($value) : $value;
        }

        return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Remplace tout caractere de controle, sauts de ligne compris, par un espace.
     */
    private static function singleLine(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    }
}
