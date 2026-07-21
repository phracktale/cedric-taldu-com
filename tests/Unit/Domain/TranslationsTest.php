<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\MissingReferenceTranslation;
use App\Domain\Locale;
use App\Domain\Translations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 05-i18n-seo §3 : le contenu FR est obligatoire, le contenu EN facultatif.
 * Une page /en/... dont la traduction manque est servie avec le texte FR,
 * precedee d'une mention discrete, et son hreflang n'est pas emis.
 *
 * Le repli ne doit JAMAIS produire un 404 ni une page vide.
 */
#[CoversClass(Translations::class)]
final class TranslationsTest extends TestCase
{
    /**
     * @param array<string, object> $traductions
     * @return Translations<object>
     */
    private function traductions(array $traductions): Translations
    {
        return new Translations($traductions);
    }

    private function contenu(string $titre): object
    {
        return new class ($titre) {
            public function __construct(public readonly string $titre)
            {
            }
        };
    }

    public function test_la_traduction_demandee_est_rendue_quand_elle_existe(): void
    {
        $traductions = $this->traductions([
            'fr' => $this->contenu('Articulation'),
            'en' => $this->contenu('Articulation (EN)'),
        ]);

        $this->assertSame('Articulation (EN)', $traductions->for(Locale::En)->titre);
    }

    public function test_une_traduction_absente_replie_sur_le_francais(): void
    {
        $traductions = $this->traductions(['fr' => $this->contenu('Articulation')]);

        $this->assertSame('Articulation', $traductions->for(Locale::En)->titre);
    }

    public function test_le_repli_est_signale(): void
    {
        // C'est ce drapeau qui declenche « This text is only available in
        // French » et qui supprime le hreflang de la paire.
        $traductions = $this->traductions(['fr' => $this->contenu('Articulation')]);

        $this->assertTrue($traductions->isAvailableIn(Locale::Fr));
        $this->assertFalse($traductions->isAvailableIn(Locale::En));
    }

    public function test_l_absence_de_francais_est_une_incoherence_de_donnees(): void
    {
        // 01-modele-de-donnees, invariant 10 : la ligne fr est obligatoire. Si
        // elle manque, la base est incoherente et il vaut mieux le savoir que
        // servir une page vide.
        $traductions = $this->traductions(['en' => $this->contenu('Only English')]);

        $this->expectException(MissingReferenceTranslation::class);

        $traductions->for(Locale::Fr);
    }

    public function test_un_ensemble_vide_est_une_incoherence_de_donnees(): void
    {
        $this->expectException(MissingReferenceTranslation::class);

        $this->traductions([])->for(Locale::Fr);
    }

    public function test_les_langues_disponibles_sont_listees(): void
    {
        $traductions = $this->traductions([
            'fr' => $this->contenu('Articulation'),
            'en' => $this->contenu('Articulation (EN)'),
        ]);

        $this->assertSame([Locale::Fr, Locale::En], $traductions->availableLocales());
    }

    public function test_les_langues_disponibles_ignorent_une_langue_inconnue_en_base(): void
    {
        // Une ligne « de » arrivee par erreur ne doit pas faire tomber la page.
        $traductions = $this->traductions([
            'fr' => $this->contenu('Articulation'),
            'de' => $this->contenu('Artikulation'),
        ]);

        $this->assertSame([Locale::Fr], $traductions->availableLocales());
    }
}
