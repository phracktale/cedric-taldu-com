<?php

declare(strict_types=1);

namespace Tests\Unit\Service\I18n;

use App\Domain\Locale;
use App\Service\I18n\Exception\MissingTranslationKey;
use App\Service\I18n\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\RecordingLogger;

/**
 * Traduction de l'interface (05-i18n-seo §4).
 *
 * `t()` échappe par défaut ; `tRaw()` laisse passer un balisage de confiance mais
 * échappe tout de même ses paramètres. Une clé manquante lève en développement
 * et retombe sur la clé en production, toujours journalisée.
 */
#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private const CATALOGS = [
        'fr' => [
            'shop.add_to_cart' => 'Ajouter au panier',
            'artwork.sold' => 'Vendue',
            'contact.greeting' => 'Bonjour :name',
            'promo.rich' => 'Voir la <strong>boutique</strong>',
        ],
        'en' => [
            'shop.add_to_cart' => 'Add to cart',
            'artwork.sold' => 'Sold',
            'contact.greeting' => 'Hello :name',
            'promo.rich' => 'See the <strong>shop</strong>',
        ],
    ];

    private function translator(bool $strict = true): Translator
    {
        return new Translator(self::CATALOGS, $strict, new RecordingLogger());
    }

    public function test_traduit_dans_la_langue_demandee(): void
    {
        $t = $this->translator();

        $this->assertSame('Ajouter au panier', $t->t('shop.add_to_cart', Locale::Fr));
        $this->assertSame('Add to cart', $t->t('shop.add_to_cart', Locale::En));
    }

    public function test_substitue_les_parametres(): void
    {
        $t = $this->translator();

        $this->assertSame('Bonjour Camille', $t->t('contact.greeting', Locale::Fr, ['name' => 'Camille']));
    }

    public function test_echappe_le_resultat_par_defaut(): void
    {
        $t = $this->translator();

        // Un paramètre hostile est neutralisé : t() échappe (05-i18n §4).
        $rendu = $t->t('contact.greeting', Locale::Fr, ['name' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $rendu);
        $this->assertStringContainsString('&lt;script&gt;', $rendu);
    }

    public function test_traw_conserve_le_balisage_de_confiance_mais_echappe_les_parametres(): void
    {
        $t = $this->translator();

        $rendu = $t->tRaw('promo.rich', Locale::Fr);
        $this->assertStringContainsString('<strong>boutique</strong>', $rendu);
    }

    public function test_une_cle_manquante_leve_en_developpement(): void
    {
        $t = $this->translator(strict: true);

        $this->expectException(MissingTranslationKey::class);
        $t->t('inconnue.cle', Locale::Fr);
    }

    public function test_une_cle_manquante_retombe_sur_la_cle_en_production(): void
    {
        $logger = new RecordingLogger();
        $t = new Translator(self::CATALOGS, false, $logger);

        // Pas d'exception : la clé elle-même est renvoyée, et l'incident journalisé.
        $this->assertSame('inconnue.cle', $t->t('inconnue.cle', Locale::Fr));
        $this->assertNotSame([], $logger->entries);
    }
}
