<?php

declare(strict_types=1);

namespace APress\Core\Extension;

use RuntimeException;

/**
 * Activation ou désactivation refusée. Le message est destiné à l'administrateur
 * (il est affiché sur la page Extensions) : il ne contient aucun chemin serveur.
 */
final class ExtensionRefused extends RuntimeException
{
}
