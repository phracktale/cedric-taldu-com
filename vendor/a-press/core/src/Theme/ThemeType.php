<?php

declare(strict_types=1);

namespace APress\Core\Theme;

/**
 * Un thème habille soit le back-office, soit le site public. Les deux se
 * choisissent séparément : themes/admin/<nom>/ et themes/front/<nom>/.
 */
enum ThemeType: string
{
    case Admin = 'admin';
    case Front = 'front';
}
