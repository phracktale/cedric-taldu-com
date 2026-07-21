<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Locale;

/**
 * Pourquoi une ligne de panier a ete corrigee (03-boutique §2).
 *
 * La spec exige un message EXPLICITE a chaque correction : un panier dont le
 * total change sans explication passe pour une erreur du site, ou pour une
 * manœuvre.
 */
enum CartNoticeReason: string
{
    /** L'œuvre originale est passee en sold pendant que le panier attendait. */
    case Acquired = 'acquired';

    /** Variante desactivee, en rupture, ou disparue du catalogue. */
    case Unavailable = 'unavailable';

    /** La ligne survit, ramenee au stock disponible. */
    case Reduced = 'reduced';

    public function message(Locale $locale, string $label, ?int $availableQuantity): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::Acquired => sprintf('« %s » a été acquise entre-temps.', $label),
                self::Unavailable => sprintf('« %s » n’est plus disponible.', $label),
                self::Reduced => sprintf(
                    'La quantité de « %s » a été ramenée à %d, seul stock disponible.',
                    $label,
                    $availableQuantity ?? 0,
                ),
            },
            Locale::En => match ($this) {
                self::Acquired => sprintf('“%s” was acquired in the meantime.', $label),
                self::Unavailable => sprintf('“%s” is no longer available.', $label),
                self::Reduced => sprintf(
                    'The quantity of “%s” was reduced to %d, the only stock available.',
                    $label,
                    $availableQuantity ?? 0,
                ),
            },
        };
    }
}
