<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\GalleryPage;
use App\Domain\Locale;
use PHPUnit\Framework\TestCase;

/**
 * Texte explicatif de la page Galerie (compte rendu client du 2026-10-12) :
 * un titre et des paragraphes par langue, saisis en texte simple. Une ligne
 * vide sépare deux paragraphes ; un simple retour à la ligne reste un retour à
 * la ligne. Rien n'est interprété : l'échappement a lieu à l'affichage.
 */
final class GalleryPageTest extends TestCase
{
    public function test_sans_reglage_la_page_n_a_ni_titre_ni_texte(): void
    {
        $page = GalleryPage::fromStored([]);

        $this->assertFalse($page->hasContent(Locale::Fr));
        $this->assertNull($page->heading(Locale::Fr));
        $this->assertSame([], $page->paragraphs(Locale::Fr));
    }

    public function test_une_ligne_vide_separe_deux_paragraphes(): void
    {
        $page = GalleryPage::fromForm([
            'titre_fr' => '  Trois corpus, une recherche ',
            'texte_fr' => "Premier paragraphe,\r\nsur deux lignes.\r\n\r\n\r\nSecond paragraphe.\n",
        ]);

        $this->assertTrue($page->hasContent(Locale::Fr));
        $this->assertSame('Trois corpus, une recherche', $page->heading(Locale::Fr));
        $this->assertSame(
            [['Premier paragraphe,', 'sur deux lignes.'], ['Second paragraphe.']],
            $page->paragraphs(Locale::Fr),
        );
    }

    public function test_un_titre_seul_ou_un_texte_seul_suffit(): void
    {
        $titre = GalleryPage::fromForm(['titre_fr' => 'Titre']);
        $this->assertTrue($titre->hasContent(Locale::Fr));
        $this->assertSame([], $titre->paragraphs(Locale::Fr));

        $texte = GalleryPage::fromForm(['texte_fr' => 'Texte.']);
        $this->assertTrue($texte->hasContent(Locale::Fr));
        $this->assertNull($texte->heading(Locale::Fr));
    }

    public function test_l_anglais_vide_reprend_le_francais_en_bloc(): void
    {
        $page = GalleryPage::fromForm(['titre_fr' => 'Titre', 'texte_fr' => 'Texte.', 'titre_en' => '', 'texte_en' => ' ']);

        $this->assertSame('Titre', $page->heading(Locale::En));
        $this->assertSame([['Texte.']], $page->paragraphs(Locale::En));
    }

    public function test_un_anglais_partiel_ne_melange_pas_les_langues(): void
    {
        // Un texte anglais sans titre ne reçoit pas le titre français.
        $page = GalleryPage::fromForm(['titre_fr' => 'Titre', 'texte_fr' => 'Texte.', 'texte_en' => 'Text.']);

        $this->assertNull($page->heading(Locale::En));
        $this->assertSame([['Text.']], $page->paragraphs(Locale::En));
    }

    public function test_les_longueurs_sont_bornees(): void
    {
        $page = GalleryPage::fromForm(['titre_fr' => str_repeat('a', 500), 'texte_fr' => str_repeat('b', 9000)]);

        $this->assertSame(GalleryPage::HEADING_MAX, mb_strlen((string) $page->heading(Locale::Fr)));
        $this->assertSame(GalleryPage::TEXT_MAX, mb_strlen($page->paragraphs(Locale::Fr)[0][0]));
    }

    public function test_le_reglage_fait_l_aller_retour(): void
    {
        $page = GalleryPage::fromForm(['titre_fr' => 'Titre', 'texte_fr' => "A\n\nB", 'titre_en' => 'Title']);

        $this->assertSame(
            ['fr' => ['heading' => 'Titre', 'text' => "A\n\nB"], 'en' => ['heading' => 'Title', 'text' => '']],
            $page->toArray(),
        );
        $this->assertEquals($page, GalleryPage::fromStored($page->toArray()));
    }

    public function test_une_valeur_stockee_inattendue_est_ignoree(): void
    {
        $page = GalleryPage::fromStored(['fr' => 'pas un tableau', 'en' => ['heading' => ['x'], 'text' => 12]]);

        $this->assertFalse($page->hasContent(Locale::Fr));
        $this->assertFalse($page->hasContent(Locale::En));
    }
}
