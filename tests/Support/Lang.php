<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\I18n\Translator;
use Tests\Support\Doubles\RecordingLogger;

/**
 * Fabrique un {@see Translator} depuis les VRAIS catalogues du projet, pour les
 * tests qui construisent une {@see \App\Core\View} sans passer par le conteneur.
 *
 * Mode strict : une clé manquante lève, comme hors production — un test qui rend
 * un gabarit se traduisant sur une clé absente échoue franchement.
 */
final class Lang
{
    public static function translator(): Translator
    {
        $root = dirname(__DIR__, 2);

        /** @var array<string, string> $fr */
        $fr = require $root . '/resources/lang/fr.php';
        /** @var array<string, string> $en */
        $en = require $root . '/resources/lang/en.php';

        return new Translator(['fr' => $fr, 'en' => $en], true, new RecordingLogger());
    }
}
