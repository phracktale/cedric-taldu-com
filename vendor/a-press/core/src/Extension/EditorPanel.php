<?php

declare(strict_types=1);

namespace APress\Core\Extension;

use APress\Core\Content\ContentType;
use APress\Core\Content\Entry;

/**
 * Panneau d'une extension dans l'éditeur, sous « Publier ».
 *
 * Rend du HTML déjà échappé par l'extension, ou null pour ne rien montrer
 * sur cette entrée.
 */
interface EditorPanel
{
    public function render(ContentType $type, Entry $entry): ?string;
}
