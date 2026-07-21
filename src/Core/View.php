<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\TemplateNotFound;
use App\Service\I18n\UrlGenerator;
use Throwable;

/**
 * Rendu de gabarits PHP natifs.
 *
 * Pas de moteur de gabarits (CLAUDE.md) : du PHP, des helpers d'echappement, et
 * deux variables exactement dans la portee du gabarit.
 *
 *  - $data : le tableau fourni par le controleur ;
 *  - $url  : le generateur d'URL, pour qu'aucun chemin ne soit ecrit en dur.
 *
 * Volontairement PAS d'extract() ni de variables variables (src/CLAUDE.md) :
 * les deux locales sont declarees explicitement, et ce qui entre dans la portee
 * d'un gabarit se lit dans cette classe et nulle part ailleurs.
 */
final class View
{
    /** Un nom de gabarit : segments en minuscules, tirets, sans extension. */
    private const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/D';

    public function __construct(
        private readonly string $templatesPath,
        private readonly UrlGenerator $url,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->resolve($template);
        $level = ob_get_level();

        ob_start();

        try {
            // Closure statique : le gabarit n'a acces ni a $this, ni au conteneur.
            (static function (string $__template, array $data, UrlGenerator $url): void {
                require $__template;
            })($file, $data, $this->url);

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            // Referme les tampons ouverts par le gabarit avant de propager :
            // sinon le fragment deja produit se colle a la page d'erreur.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        }
    }

    private function resolve(string $template): string
    {
        if (preg_match(self::NAME_PATTERN, $template) !== 1) {
            throw TemplateNotFound::forName($template);
        }

        $root = realpath($this->templatesPath);
        $file = realpath($this->templatesPath . '/' . $template . '.php');

        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw TemplateNotFound::forName($template);
        }

        // realpath rend la casse reelle du systeme de fichiers. Sous Windows, un
        // « Simple » resolu en « simple.php » passerait sans ce controle, puis
        // echouerait sur Thor ou la casse est significative.
        $expected = $root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $template) . '.php';

        if ($file !== $expected) {
            throw TemplateNotFound::forName($template);
        }

        return $file;
    }
}
