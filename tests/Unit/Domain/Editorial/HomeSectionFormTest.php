<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\HomeSectionForm;
use PHPUnit\Framework\TestCase;

/**
 * Édition du contenu des sections de l'accueil (revue du 2026-09-24).
 *
 * Le formulaire poste des champs SCALAIRES à plat (Core\Request ne lit pas les
 * tableaux) : `titre_fr`, `cta_target`, `cellule2_texte_en`… HomeSectionForm
 * les range dans le document du réglage : une partie par langue, une partie
 * `common` pour ce qui ne se traduit pas (cible du CTA, fond, œuvres).
 */
final class HomeSectionFormTest extends TestCase
{
    public function test_seules_les_sections_a_contenu_sont_editables(): void
    {
        $this->assertTrue(HomeSectionForm::isEditable('hero'));
        $this->assertTrue(HomeSectionForm::isEditable('atelier'));
        $this->assertFalse(HomeSectionForm::isEditable('galeries'));
        $this->assertFalse(HomeSectionForm::isEditable('evil'));
        $this->assertSame('home.studio', HomeSectionForm::settingKey('atelier'));
    }

    public function test_les_textes_sont_ranges_par_langue(): void
    {
        $document = HomeSectionForm::apply('contact', [], [
            'eyebrow_fr' => 'Contact',
            'title_fr' => 'Écrivez-moi',
            'title_en' => 'Write to me',
            'text_fr' => "Réponse sous 48 h.",
        ], null);

        $this->assertSame('Écrivez-moi', $document['fr']['title']);
        $this->assertSame('Write to me', $document['en']['title']);
        $this->assertSame('Réponse sous 48 h.', $document['fr']['text']);
    }

    public function test_un_texte_vide_est_retire_pour_laisser_jouer_le_repli(): void
    {
        $document = HomeSectionForm::apply('contact', ['en' => ['title' => 'Old']], [
            'title_fr' => 'Titre',
            'title_en' => '   ',
        ], null);

        $this->assertArrayNotHasKey('title', $document['en']);
    }

    public function test_les_textes_sont_nettoyes_et_bornes(): void
    {
        $document = HomeSectionForm::apply('hero', [], [
            'title_fr' => "  Titre\x00 propre  ",
            'eyebrow_fr' => str_repeat('a', 5000),
        ], null);

        $this->assertSame('Titre propre', $document['fr']['title']);
        $this->assertSame(300, mb_strlen($document['fr']['eyebrow']));
    }

    public function test_le_cta_range_libelle_par_langue_et_reglages_en_commun(): void
    {
        $document = HomeSectionForm::apply('hero', [], [
            'cta_fr' => 'Lire le livret',
            'cta_en' => 'Read the booklet',
            'cta_affiche' => '1',
            'cta_target' => 'booklet',
            'cta_style' => 'vide',
            'cta_align' => 'gauche',
        ], null);

        $this->assertSame('Lire le livret', $document['fr']['cta']);
        $this->assertSame('Read the booklet', $document['en']['cta']);
        $this->assertSame('booklet', $document['common']['cta']['target']);
        $this->assertSame('vide', $document['common']['cta']['style']);
        $this->assertSame('gauche', $document['common']['cta']['align']);
        $this->assertTrue($document['common']['cta']['enabled']);
    }

    public function test_decocher_le_cta_le_masque(): void
    {
        $document = HomeSectionForm::apply('boutique', [], ['cta_target' => 'contact'], null);

        $this->assertFalse($document['common']['cta']['enabled']);
    }

    public function test_une_cible_hostile_est_neutralisee(): void
    {
        $document = HomeSectionForm::apply('hero', [], [
            'cta_affiche' => '1',
            'cta_target' => 'url',
            'cta_url' => 'javascript:alert(1)',
        ], null);

        $this->assertSame('galleries', $document['common']['cta']['target']);
        $this->assertNull($document['common']['cta']['url']);
    }

    public function test_chaque_section_a_sa_cible_par_defaut(): void
    {
        // Sans réglage, les boutons gardent leur comportement historique.
        $this->assertSame('galleries', HomeSectionForm::ctaCommon('hero', [])['target']);
        $this->assertSame('about', HomeSectionForm::ctaCommon('atelier', [])['target']);
        $this->assertSame('vide', HomeSectionForm::ctaCommon('atelier', [])['style']);
        $this->assertSame('contact', HomeSectionForm::ctaCommon('contact', [])['target']);
        $this->assertTrue(HomeSectionForm::ctaCommon('contact', [])['enabled']);
    }

    public function test_le_fond_du_hero_garde_image_couleur_et_ton(): void
    {
        $document = HomeSectionForm::apply('hero', [], [
            'fond_couleur' => '#1A2B3C',
            'fond_ton' => 'papier',
        ], 42);

        $this->assertSame(
            ['media_id' => 42, 'color' => '#1a2b3c', 'tone' => 'papier'],
            $document['common']['background'],
        );
    }

    public function test_une_couleur_invalide_ou_un_ton_inconnu_sont_ecartes(): void
    {
        $document = HomeSectionForm::apply('hero', [], [
            'fond_couleur' => 'red;}body{display:none',
            'fond_ton' => 'fluo',
        ], null);

        $this->assertNull($document['common']['background']['color']);
        $this->assertSame('encre', $document['common']['background']['tone']);
    }

    public function test_retirer_l_image_de_fond(): void
    {
        $document = HomeSectionForm::apply(
            'hero',
            ['common' => ['background' => ['media_id' => 42, 'color' => null, 'tone' => 'encre']]],
            ['fond_retirer' => '1'],
            42,
        );

        $this->assertNull($document['common']['background']['media_id']);
    }

    public function test_le_triptyque_range_ses_trois_cellules(): void
    {
        $document = HomeSectionForm::apply('triptyque', [], [
            'title_fr' => 'Corps',
            'cellule1_titre_fr' => 'Visible',
            'cellule1_texte_fr' => 'Exposé',
            'cellule3_titre_fr' => 'Vécu',
        ], null);

        $this->assertSame(
            [
                ['title' => 'Visible', 'text' => 'Exposé'],
                ['title' => 'Vécu', 'text' => ''],
            ],
            $document['fr']['cells'],
        );
    }

    public function test_les_paragraphes_de_l_atelier_se_separent_par_une_ligne_vide(): void
    {
        $document = HomeSectionForm::apply('atelier', [], [
            'paragraphs_fr' => "Premier paragraphe.\r\n\r\nSecond\nsur deux lignes.\n\n\n",
        ], 7);

        $this->assertSame(['Premier paragraphe.', "Second\nsur deux lignes."], $document['fr']['paragraphs']);
        $this->assertSame(7, $document['common']['portrait_media_id']);
    }

    public function test_la_vitrine_garde_trois_oeuvres_dans_l_ordre(): void
    {
        $document = HomeSectionForm::apply('vitrine', [], [
            'vitrine_1' => '12',
            'vitrine_2' => '',
            'vitrine_3' => '5',
        ], null);

        // Stockée comme le jeu de démonstration : sous « fr », lu en repli par l'anglais.
        $this->assertSame([12, 5], $document['fr']['artwork_ids']);
    }

    public function test_les_autres_clefs_du_document_sont_preservees(): void
    {
        // home.news porte des « items » que le formulaire n'édite pas.
        $document = HomeSectionForm::apply('actus', ['fr' => ['items' => [['title' => 'Salon']]]], [
            'title_fr' => 'Expositions',
        ], null);

        $this->assertSame([['title' => 'Salon']], $document['fr']['items']);
        $this->assertSame('Expositions', $document['fr']['title']);
    }

    public function test_to_form_remet_le_document_a_plat(): void
    {
        $valeurs = HomeSectionForm::toForm('atelier', [
            'fr' => ['title' => 'Né au Havre', 'paragraphs' => ['Un.', 'Deux.'], 'cta' => 'Mon parcours'],
            'common' => ['cta' => ['target' => 'booklet', 'enabled' => false], 'portrait_media_id' => 3],
        ]);

        $this->assertSame('Né au Havre', $valeurs['title_fr']);
        $this->assertSame("Un.\n\nDeux.", $valeurs['paragraphs_fr']);
        $this->assertSame('Mon parcours', $valeurs['cta_fr']);
        $this->assertSame('booklet', $valeurs['cta_target']);
        $this->assertSame('', $valeurs['cta_affiche']);
        $this->assertSame('3', $valeurs['portrait']);
    }
}
