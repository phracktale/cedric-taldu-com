<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\TemplateNotFound;
use App\Domain\Locale;
use App\Service\I18n\Translator;
use App\Service\I18n\UrlGenerator;
use Throwable;

/**
 * Rendu de gabarits PHP natifs.
 *
 * Pas de moteur de gabarits (CLAUDE.md) : du PHP, des helpers d'echappement, et
 * deux variables exactement dans la portee du gabarit.
 *
 *  - $data : le tableau fourni par le controleur ;
 *  - $url  : le generateur d'URL, pour qu'aucun chemin ne soit ecrit en dur ;
 *  - $t / $tRaw : la traduction d'interface, liee a la langue du gabarit.
 *
 * Volontairement PAS d'extract() ni de variables variables (src/CLAUDE.md) :
 * les entrees dans la portee d'un gabarit se lisent dans cette classe et nulle
 * part ailleurs. `$t` est une closure, et non une fonction globale : le
 * catalogue et la langue courante sont un etat, que le projet interdit de porter
 * en global (comme `$url`, qui depend du prefixe resolu par la requete).
 */
final class View
{
    /** Un nom de gabarit : segments en minuscules, tirets, sans extension. */
    private const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/D';

    public function __construct(
        private readonly string $templatesPath,
        private readonly UrlGenerator $url,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param string|null $layout mise en page qui recevra le rendu dans $content
     */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->evaluate($this->resolve($template), $data, '');

        if ($layout === null) {
            return $content;
        }

        return $this->evaluate($this->resolve($layout), $data, $content);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function evaluate(string $file, array $data, string $content): string
    {
        $level = ob_get_level();

        // Composition de partiels. L'en-tete, le pied de page et la vignette
        // d'œuvre servent a toutes les pages : les faire rendre par le
        // controleur et les passer en donnee obligerait chaque controleur a
        // connaitre la mise en page.
        //
        // Le partiel ne recoit QUE ce qu'on lui passe : un partiel qui
        // heriterait du contexte du parent rendrait impossible de savoir ce
        // dont il depend.
        $partial =
            /**
             * @param array<string, mixed> $partialData
             */
            fn (string $template, array $partialData = []): string
                => $this->evaluate($this->resolve($template), $partialData, '');

        // La langue du gabarit vient de son contexte ; un partiel qui n'en
        // recoit pas retombe sur le francais, langue de reference.
        $locale = ($data['locale'] ?? null) instanceof Locale ? $data['locale'] : Locale::reference();

        $t =
            /** @param array<string, string|int> $params */
            fn (string $key, array $params = []): string => $this->translator->t($key, $locale, $params);

        $tRaw =
            /** @param array<string, string|int> $params */
            fn (string $key, array $params = []): string => $this->translator->tRaw($key, $locale, $params);

        ob_start();

        try {
            // Closure statique : le gabarit n'a acces ni a $this, ni au conteneur.
            (static function (
                string $__template,
                array $data,
                UrlGenerator $url,
                string $content,
                callable $partial,
                callable $t,
                callable $tRaw,
            ): void {
                require $__template;
            })($file, $data, $this->url, $content, $partial, $t, $tRaw);

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
