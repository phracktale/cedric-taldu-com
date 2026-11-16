<?php

declare(strict_types=1);

namespace APress\Core\Content;

use RuntimeException;

/**
 * Modèle de contenu incohérent. Levée au chargement : un modèle faux ne doit
 * jamais produire d'écran ni d'API à moitié justes.
 */
final class InvalidModel extends RuntimeException
{
}
