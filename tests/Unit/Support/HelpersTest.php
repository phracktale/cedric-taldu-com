<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Les helpers d'echappement sont le seul moyen autorise d'ecrire une valeur dans
 * un gabarit (src/CLAUDE.md). EscapingTest verifie qu'aucun « <?= » ne s'en passe ;
 * ce test-ci verifie qu'ils echappent effectivement.
 */
final class HelpersTest extends TestCase
{
    public function test_e_echappe_les_balises(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
    }

    public function test_e_echappe_les_deux_sortes_de_guillemets(): void
    {
        // ENT_QUOTES : sans l'apostrophe, une valeur placee dans un attribut
        // delimite par des apostrophes s'echapperait de l'attribut.
        $this->assertSame('&quot;double&quot; &#039;simple&#039;', e('"double" \'simple\''));
    }

    public function test_e_conserve_les_accents(): void
    {
        $this->assertSame('Œuvre récente — à l’encre', e('Œuvre récente — à l’encre'));
    }

    public function test_e_remplace_les_octets_invalides_au_lieu_de_tout_vider(): void
    {
        // ENT_SUBSTITUTE : sans lui, htmlspecialchars renvoie une chaine vide sur
        // de l'UTF-8 invalide, et un champ disparait silencieusement de la page.
        $resultat = e("valide\x80invalide");

        $this->assertNotSame('', $resultat);
        $this->assertStringContainsString('valide', $resultat);
    }

    public function test_e_accepte_null_et_les_nombres(): void
    {
        $this->assertSame('', e(null));
        $this->assertSame('2026', e(2026));
        $this->assertSame('16.5', e(16.5));
    }

    public function test_attr_echappe_de_quoi_sortir_d_un_attribut(): void
    {
        $this->assertSame(
            '&quot; onerror=&quot;alert(1)',
            attr('" onerror="alert(1)')
        );
    }

    public function test_jsonAttr_neutralise_la_fermeture_de_balise_script(): void
    {
        $resultat = jsonAttr(['titre' => '</script><script>alert(1)</script>']);

        // json_encode echappe deja « / » en « \/ », mais c'est « < » qui compte :
        // sans JSON_HEX_TAG, « </script> » fermerait le bloc script qui porte les
        // donnees, quel que soit l'echappement des slashs.
        $this->assertStringNotContainsString('<', $resultat);
        $this->assertStringNotContainsString('>', $resultat);
        $this->assertStringNotContainsString('</script', $resultat);
    }

    public function test_jsonAttr_est_utilisable_dans_un_attribut_a_guillemets_doubles(): void
    {
        $resultat = jsonAttr(['titre' => 'Pilier I']);

        $this->assertStringNotContainsString('"', $resultat);
    }

    public function test_jsonAttr_reste_relisible_par_json_parse_apres_decodage_html(): void
    {
        $donnees = ['titre' => 'Pilier « I » & <co>', 'prix' => 45000];

        $decode = html_entity_decode(jsonAttr($donnees), ENT_QUOTES, 'UTF-8');

        $this->assertSame($donnees, json_decode($decode, true));
    }
}
