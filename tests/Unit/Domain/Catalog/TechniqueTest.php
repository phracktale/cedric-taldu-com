<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Technique;
use App\Domain\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Technique d'une œuvre (retours Search Console du 2026-10-26).
 *
 * Saisie en texte libre jusqu'ici, tantôt en anglais, tantôt en français : on
 * lisait « Oil on canvas » sur la page française, et dans le balisage. Les
 * techniques de l'atelier deviennent une liste, traduite, et décomposée pour
 * schema.org en forme (artform), médium (artMedium) et support
 * (artworkSurface).
 */
final class TechniqueTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Technique}>
     */
    public static function saisies(): iterable
    {
        yield 'code encre' => ['ink_on_paper', Technique::InkOnPaper];
        yield 'code huile' => ['oil_on_canvas', Technique::OilOnCanvas];
        yield 'ancien texte français' => ['Encre de Chine sur papier', Technique::InkOnPaper];
        yield 'ancien texte anglais' => ['India ink on paper', Technique::InkOnPaper];
        yield 'huile en français' => ['Huile sur toile', Technique::OilOnCanvas];
        yield 'huile en anglais, casse et espaces libres' => ['  oil ON canvas ', Technique::OilOnCanvas];
    }

    #[DataProvider('saisies')]
    public function test_un_code_ou_un_ancien_libelle_designe_sa_technique(string $saisie, Technique $attendue): void
    {
        $this->assertSame($attendue, Technique::resolve($saisie));
    }

    public function test_une_technique_hors_liste_n_est_pas_reconnue(): void
    {
        $this->assertNull(Technique::resolve('Graphite sur papier'));
        $this->assertNull(Technique::resolve(null));
        $this->assertNull(Technique::resolve(''));
    }

    public function test_la_technique_se_dit_dans_la_langue_de_la_page(): void
    {
        $this->assertSame('Encre de Chine sur papier', Technique::InkOnPaper->label(Locale::Fr));
        $this->assertSame('India ink on paper', Technique::InkOnPaper->label(Locale::En));
        $this->assertSame('Huile sur toile', Technique::OilOnCanvas->label(Locale::Fr));
        $this->assertSame('Oil on canvas', Technique::OilOnCanvas->label(Locale::En));
    }

    public function test_dans_une_phrase_la_technique_garde_ses_majuscules_utiles(): void
    {
        // « encre de Chine » : Chine garde sa majuscule ; « India » aussi.
        $this->assertSame('encre de Chine sur papier', Technique::InkOnPaper->inline(Locale::Fr));
        $this->assertSame('India ink on paper', Technique::InkOnPaper->inline(Locale::En));
        $this->assertSame('huile sur toile', Technique::OilOnCanvas->inline(Locale::Fr));
        $this->assertSame('oil on canvas', Technique::OilOnCanvas->inline(Locale::En));
    }

    public function test_la_technique_se_decompose_en_forme_medium_et_support(): void
    {
        $this->assertSame(
            ['artform' => 'Dessin', 'artMedium' => 'Encre de Chine', 'artworkSurface' => 'Papier'],
            Technique::InkOnPaper->schema(Locale::Fr),
        );
        $this->assertSame(
            ['artform' => 'Painting', 'artMedium' => 'Oil', 'artworkSurface' => 'Canvas'],
            Technique::OilOnCanvas->schema(Locale::En),
        );
        $this->assertSame(
            ['artform' => 'Peinture', 'artMedium' => 'Huile', 'artworkSurface' => 'Toile'],
            Technique::OilOnCanvas->schema(Locale::Fr),
        );
        $this->assertSame(
            ['artform' => 'Drawing', 'artMedium' => 'India ink', 'artworkSurface' => 'Paper'],
            Technique::InkOnPaper->schema(Locale::En),
        );
    }
}
