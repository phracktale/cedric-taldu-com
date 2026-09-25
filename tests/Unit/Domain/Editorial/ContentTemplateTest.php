<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\ContentTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Modèles de contenu (retours du 2026-09-25) : un modèle par type (page, actu,
 * galerie, contact, œuvre), composé par glisser-déposer de ses sections.
 */
final class ContentTemplateTest extends TestCase
{
    public function test_chaque_type_a_ses_sections_et_un_modele_par_defaut_complet(): void
    {
        $this->assertSame(['page', 'post', 'category', 'contact', 'artwork'], array_keys(ContentTemplate::TYPES));

        $this->assertSame(
            ['header', 'cover', 'body', 'blocks', 'pdf'],
            ContentTemplate::default('page')->sections(),
        );
    }

    public function test_la_liste_composee_donne_l_ordre_et_retire_le_reste(): void
    {
        $modele = ContentTemplate::fromList('post', ['header', 'body', 'cta', 'evil', 'body']);

        $this->assertSame(['header', 'body', 'cta'], $modele->sections());
    }

    public function test_une_section_obligatoire_retiree_est_remise_a_sa_place_d_origine(): void
    {
        // Le titre et le contenu ne peuvent pas disparaître d'une page.
        $modele = ContentTemplate::fromList('page', ['cover']);

        $this->assertSame(['header', 'cover', 'body'], $modele->sections());
    }

    public function test_un_type_inconnu_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentTemplate::default('evil');
    }

    public function test_le_reglage_stocke_se_relit_et_son_absence_donne_le_defaut(): void
    {
        $this->assertSame(['breadcrumb', 'main'], ContentTemplate::fromStored('artwork', ['breadcrumb', 'main'])->sections());
        $this->assertSame(ContentTemplate::default('contact')->sections(), ContentTemplate::fromStored('contact', [])->sections());
    }
}
