<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\UnsupportedLocale;
use App\Domain\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Locale::class)]
final class LocaleTest extends TestCase
{
    public function test_les_deux_langues_du_site_sont_declarees(): void
    {
        $this->assertSame('fr', Locale::Fr->value);
        $this->assertSame('en', Locale::En->value);
        $this->assertCount(2, Locale::cases());
    }

    public function test_une_langue_est_construite_depuis_son_code(): void
    {
        $this->assertSame(Locale::En, Locale::fromString('en'));
    }

    public function test_le_code_est_normalise(): void
    {
        // Accept-Language peut envoyer « EN » ou « en-GB » ; la table de routes,
        // elle, ne connait que « en ».
        $this->assertSame(Locale::En, Locale::fromString('EN'));
        $this->assertSame(Locale::En, Locale::fromString(' en '));
    }

    public function test_une_langue_non_servie_est_refusee(): void
    {
        // 05-i18n-seo §1 : deux langues, pas de troisieme sans configuration
        // ET traductions. On ne devine pas.
        $this->expectException(UnsupportedLocale::class);

        Locale::fromString('de');
    }

    public function test_le_francais_est_la_langue_de_reference(): void
    {
        // 01-modele-de-donnees, invariant 10 : chaque enregistrement
        // traduisible possede au minimum sa ligne fr. La ligne en est
        // facultative et declenche un repli.
        $this->assertSame(Locale::Fr, Locale::reference());
        $this->assertTrue(Locale::Fr->isReference());
        $this->assertFalse(Locale::En->isReference());
    }

    public function test_une_langue_connait_son_code_html(): void
    {
        $this->assertSame('fr', Locale::Fr->htmlLang());
        $this->assertSame('en', Locale::En->htmlLang());
    }

    public function test_une_langue_porte_son_nom_dans_sa_propre_langue(): void
    {
        // Un sélecteur de langue qui écrit « Anglais » plutôt que « English »
        // est illisible pour celui qui en a besoin.
        $this->assertSame('Français', Locale::Fr->nativeName());
        $this->assertSame('English', Locale::En->nativeName());
    }
}
