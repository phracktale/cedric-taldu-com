<?php

declare(strict_types=1);

namespace App\Service\I18n;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Domain\Locale;
use App\Service\I18n\Exception\MissingTranslationKey;

/**
 * Traduction de l'interface (05-i18n-seo §4).
 *
 * Les libellés vivent dans `resources/lang/{fr,en}.php`, tableaux plats de clés
 * (`shop.add_to_cart`, `artwork.sold`). Un test de parité garantit que les deux
 * catalogues portent EXACTEMENT les mêmes clés : une clé présente dans une seule
 * langue est un défaut, pas un repli.
 *
 * `t()` échappe par défaut : c'est la sortie sûre, la seule employée dans les
 * gabarits. `tRaw()` laisse passer un balisage de CONFIANCE (celui du catalogue)
 * mais échappe tout de même ses paramètres, qui peuvent venir de l'utilisateur.
 *
 * Clé manquante : on lève en développement (le défaut se voit tôt), on retombe
 * sur la clé en production, toujours journalisée — jamais une page cassée.
 */
final class Translator
{
    /**
     * @param array<string, array<string, string>> $catalogs indexés par code de langue
     * @param bool $strict lève sur une clé manquante (vrai hors production)
     */
    public function __construct(
        private readonly array $catalogs,
        private readonly bool $strict,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Libellé traduit et ÉCHAPPÉ, prêt à écrire dans un gabarit.
     *
     * @param array<string, string|int> $params substitués à `:nom`, échappés
     */
    public function t(string $key, Locale $locale, array $params = []): string
    {
        return $this->escape($this->substitute($this->lookup($key, $locale), $params));
    }

    /**
     * Libellé traduit conservant son balisage de confiance, paramètres échappés.
     *
     * Réservé aux rares libellés portant du HTML (un mot en gras, un lien). Son
     * usage est borné par un test (05-i18n-seo §4).
     *
     * @param array<string, string|int> $params substitués à `:nom`, échappés
     */
    public function tRaw(string $key, Locale $locale, array $params = []): string
    {
        $escaped = [];

        foreach ($params as $name => $value) {
            $escaped[$name] = $this->escape((string) $value);
        }

        return $this->substitute($this->lookup($key, $locale), $escaped);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function substitute(string $text, array $params): string
    {
        if ($params === []) {
            return $text;
        }

        $pairs = [];

        foreach ($params as $name => $value) {
            $pairs[':' . $name] = (string) $value;
        }

        return strtr($text, $pairs);
    }

    private function lookup(string $key, Locale $locale): string
    {
        $catalog = $this->catalogs[$locale->value] ?? [];

        if (isset($catalog[$key])) {
            return $catalog[$key];
        }

        if ($this->strict) {
            throw MissingTranslationKey::forKey($key, $locale);
        }

        $this->logger->log(LogLevel::Warning, 'Clé de traduction manquante', [
            'key' => $key,
            'locale' => $locale->value,
        ]);

        return $key;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
