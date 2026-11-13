<?php

declare(strict_types=1);

namespace APress\Core;

/**
 * Moteur de gabarits vu par le cœur.
 *
 * Le cœur n'impose pas de moteur : chaque site (ou, au lot 4, le résolveur de
 * thèmes) fournit le sien. ErrorResponder n'a besoin que de cette signature.
 */
interface Renderer
{
    /**
     * @param array<string, mixed> $data
     * @param string|null $layout mise en page qui recevra le rendu dans $content
     */
    public function render(string $template, array $data = [], ?string $layout = null): string;
}
