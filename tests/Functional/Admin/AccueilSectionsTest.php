<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Contenu des sections de l'accueil, édité en back-office (revue du 2026-09-24).
 */
final class AccueilSectionsTest extends AdminTestCase
{
    private const ADMIN = '/cedric-taldu/admin/accueil';
    private const ACCUEIL = '/cedric-taldu/fr/';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_d_ordre_mene_au_contenu_de_chaque_section(): void
    {
        $corps = $this->requete('GET', self::ADMIN)->body;

        $this->assertStringContainsString('href="' . self::ADMIN . '/hero"', $corps);
        $this->assertStringContainsString('href="' . self::ADMIN . '/atelier"', $corps);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formulaires(): iterable
    {
        yield 'hero' => ['hero', 'name="fond_fichier"'];
        yield 'vitrine' => ['vitrine', 'name="vitrine_1"'];
        yield 'triptyque' => ['triptyque', 'name="cellule3_texte_en"'];
        yield 'boutique' => ['boutique', 'name="cta_target"'];
        yield 'atelier' => ['atelier', 'name="portrait_fichier"'];
        yield 'actus' => ['actus', 'name="title_fr"'];
        yield 'contact' => ['contact', 'name="cta_align"'];
    }

    #[DataProvider('formulaires')]
    public function test_chaque_section_a_son_formulaire(string $section, string $champ): void
    {
        $reponse = $this->requete('GET', self::ADMIN . '/' . $section);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString($champ, $reponse->body);
    }

    public function test_une_section_sans_contenu_ou_inconnue_repond_404(): void
    {
        $this->assertSame(404, $this->requete('GET', self::ADMIN . '/galeries')->status);
        $this->assertSame(404, $this->requete('GET', self::ADMIN . '/evil')->status);
    }

    public function test_le_hero_edite_pilote_l_accueil_public(): void
    {
        $reponse = $this->postAvecJeton(self::ADMIN . '/hero', [
            'title_fr' => 'Un nouveau titre',
            'cta_fr' => 'Lire le livret',
            'cta_affiche' => '1',
            'cta_target' => 'booklet',
            'cta_align' => 'gauche',
        ]);

        $this->assertSame(302, $reponse->status);
        $corps = $this->requete('GET', self::ACCUEIL)->body;
        $this->assertStringContainsString('<h1>Un nouveau titre</h1>', $corps);
        $this->assertMatchesRegularExpression('~href="/cedric-taldu/fr/livret"[^>]*>\s*Lire le livret~', $corps);
        $this->assertStringContainsString('cta-row--gauche', $corps);
    }

    public function test_un_cta_decoche_disparait(): void
    {
        $this->reglage('home.contact', ['fr' => ['title' => 'Rester en lien']]);

        $this->postAvecJeton(self::ADMIN . '/contact', ['title_fr' => 'Rester en lien']);

        $section = $this->section($this->requete('GET', self::ACCUEIL)->body, 'id="contact"');
        $this->assertStringNotContainsString('class="btn', $section);
    }

    public function test_la_couleur_de_fond_du_hero_passe_par_une_feuille_de_style_a_nonce(): void
    {
        // La CSP interdit les attributs style : la couleur vit dans un <style nonce>.
        $this->postAvecJeton(self::ADMIN . '/hero', ['fond_couleur' => '#123456', 'fond_ton' => 'papier']);

        $corps = $this->requete('GET', self::ACCUEIL)->body;

        $this->assertMatchesRegularExpression('~<style nonce="[^"]+">[^<]*--hero-fond: #123456~', $corps);
        $this->assertStringContainsString('data-ton="papier"', $corps);
        $this->assertStringNotContainsString('style="', $this->section($corps, 'class="hero'));
    }

    public function test_l_image_de_fond_du_hero_est_une_vraie_picture(): void
    {
        $media = (new MediaFactory($this->pdo))->named('fond-atelier')->create();

        $this->postAvecJeton(self::ADMIN . '/hero', ['fond' => (string) $media]);

        $hero = $this->section($this->requete('GET', self::ACCUEIL)->body, 'class="hero');
        $this->assertStringContainsString('hero-fond', $hero);
        $this->assertStringContainsString('fond-atelier', $hero);
    }

    public function test_le_portrait_de_l_atelier_remplace_l_emplacement_vide(): void
    {
        $media = (new MediaFactory($this->pdo))->named('portrait-cedric')->create();

        $this->postAvecJeton(self::ADMIN . '/atelier', ['title_fr' => 'L’artiste', 'portrait' => (string) $media]);

        $atelier = $this->section($this->requete('GET', self::ACCUEIL)->body, 'id="atelier"');
        $this->assertStringContainsString('portrait-cedric', $atelier);
    }

    public function test_le_contenu_saisi_est_echappe(): void
    {
        $this->postAvecJeton(self::ADMIN . '/hero', ['title_fr' => '<script>alert(1)</script>']);

        $corps = $this->requete('GET', self::ACCUEIL)->body;

        $this->assertStringNotContainsString('<script>alert(1)</script>', $corps);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $corps);
    }

    public function test_l_enregistrement_sans_jeton_csrf_est_refuse(): void
    {
        $reponse = $this->requete('POST', self::ADMIN . '/hero', post: ['title_fr' => 'X']);

        $this->assertContains($reponse->status, [403, 419]);
    }

    /**
     * @param array<mixed> $valeur
     */
    private function reglage(string $cle, array $valeur): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute(['k' => $cle, 'v' => json_encode($valeur, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Balise <section> qui porte le marqueur donné, jusqu'à sa fermeture.
     */
    private function section(string $html, string $marqueur): string
    {
        $pos = strpos($html, $marqueur);
        $this->assertNotFalse($pos, 'Section absente : ' . $marqueur);
        $debut = strrpos(substr($html, 0, $pos), '<section');
        $this->assertNotFalse($debut);
        $fin = strpos($html, '</section>', $pos);
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }
}
