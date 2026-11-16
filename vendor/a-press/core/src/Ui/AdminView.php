<?php

declare(strict_types=1);

namespace APress\Core\Ui;

use APress\Core\Renderer;
use APress\Core\Theme\TemplatePaths;
use Throwable;

/**
 * Rendu des gabarits PHP du cœur (et de leurs surcharges par thème, extension
 * ou site, via TemplatePaths).
 *
 * Dans un gabarit, exactement quatre variables : `$data` (fourni par le
 * contrôleur), `$url` (UrlBuilder), `$h` (Html, l'échappement) et `$partial`
 * (rendu d'un sous-gabarit). Pas d'extract().
 */
final class AdminView implements Renderer
{
    public function __construct(
        private readonly TemplatePaths $paths,
        private readonly UrlBuilder $url,
    ) {
    }

    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->evaluate($this->paths->find($template), $data, '');

        return $layout === null ? $content : $this->evaluate($this->paths->find($layout), $data, $content);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function evaluate(string $file, array $data, string $content): string
    {
        $url = $this->url;
        $h = Html::class;
        $partial = fn (string $template, array $partialData = []): string
            => $this->evaluate($this->paths->find($template), $partialData, '');

        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__file, array $data, UrlBuilder $url, string $h, callable $partial, string $content): void {
                require $__file;
            })($file, $data, $url, $h, $partial, $content);

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}
