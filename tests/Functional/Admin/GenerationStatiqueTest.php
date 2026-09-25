<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Repository\Admin\SettingsAdminRepository;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\PostFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Génération statique du site public (retours du 2026-09-25, point 7).
 *
 * Les pages publiques sont rendues une fois en fichiers HTML, servis tels quels
 * par Apache. Le panier, le tunnel, le compte et le contact restent en PHP. La
 * barre fixe du back-office donne le nombre de pages, le numéro de génération,
 * sa date et un journal page à page.
 */
final class GenerationStatiqueTest extends AdminTestCase
{
    private const GENERER = '/cedric-taldu/admin/generation';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $galerie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('fr', 'pilier', 'Pilier')->create($galerie);
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'vernissage', 'Vernissage', '<p>Le corps.</p>')->create();
    }

    public function test_la_generation_ecrit_les_pages_publiques_et_seulement_elles(): void
    {
        $this->assertSame(303, $this->postAvecJeton(self::GENERER)->status);

        foreach ([
            'fr/index.html',
            'en/index.html',
            'fr/galerie/index.html',
            'fr/galerie/encres/index.html',
            'fr/oeuvre/pilier/index.html',
            'fr/actus/index.html',
            'fr/actus/vernissage/index.html',
        ] as $fichier) {
            $this->assertFileExists($this->dossierStatique() . '/' . $fichier);
        }

        // Formulaire à jeton horodaté, panier, tunnel : toujours dynamiques.
        $this->assertFileDoesNotExist($this->dossierStatique() . '/fr/contact/index.html');
        $this->assertFileDoesNotExist($this->dossierStatique() . '/fr/panier/index.html');
    }

    public function test_une_page_statique_ne_porte_ni_jeton_de_session_ni_nonce(): void
    {
        $jeton = $this->jetonCsrf();
        $this->postAvecJeton(self::GENERER);

        $html = (string) file_get_contents($this->dossierStatique() . '/fr/oeuvre/pilier/index.html');

        $this->assertStringContainsString('<h1>Pilier</h1>', $html);
        $this->assertStringNotContainsString($jeton, $html);
        $this->assertStringContainsString('name="_token" value=""', $html);
        $this->assertStringNotContainsString('nonce=', $html);
        // Repère lu par etat.js, qui complète jeton et pastille du panier.
        $this->assertStringContainsString('data-static', $html);
    }

    public function test_la_csp_des_pages_statiques_autorise_le_style_par_empreinte(): void
    {
        (new SettingsAdminRepository($this->pdo))->save('nav.active_style', ['color' => '#aa3300'], $this->horloge->now());

        $this->postAvecJeton(self::GENERER);

        $html = (string) file_get_contents($this->dossierStatique() . '/fr/index.html');
        $this->assertSame(1, preg_match('#<style>(.*?)</style>#s', $html, $style));
        $empreinte = "'sha256-" . base64_encode(hash('sha256', $style[1], true)) . "'";

        $htaccess = (string) file_get_contents($this->dossierStatique() . '/.htaccess');
        $this->assertStringContainsString('Content-Security-Policy', $htaccess);
        $this->assertStringContainsString("style-src 'self' " . $empreinte, $htaccess);
        $this->assertStringNotContainsString('nonce-', $htaccess);
        // Préproduction : jamais indexée, même servie par Apache seul.
        $this->assertStringContainsString('X-Robots-Tag', $htaccess);
    }

    public function test_la_barre_donne_le_numero_le_nombre_de_pages_et_le_journal(): void
    {
        $avant = $this->requete('GET', '/cedric-taldu/admin')->body;
        $this->assertStringContainsString('class="barre-generation"', $avant);
        $this->assertStringContainsString('Aucune génération', $avant);

        $this->postAvecJeton(self::GENERER);
        $this->postAvecJeton(self::GENERER);

        $corps = $this->requete('GET', '/cedric-taldu/admin')->body;
        $this->assertStringContainsString('Génération n° 2', $corps);
        $this->assertMatchesRegularExpression('#<span class="barre-pages">\d+ pages statiques</span>#', $corps);
        // Journal de type terminal : une ligne par page, horodatée et chronométrée.
        $this->assertStringContainsString('class="barre-journal"', $corps);
        $this->assertMatchesRegularExpression('#\[\d{2}:\d{2}:\d{2}\.\d{3}\] /cedric-taldu/fr/galerie/encres +\d+ ms#', $corps);
        $this->assertMatchesRegularExpression('#Total : \d+ pages en [\d,]+ s#', $corps);
    }

    public function test_une_modification_en_back_office_perime_le_site_statique(): void
    {
        $this->postAvecJeton(self::GENERER);
        $this->assertFileExists($this->dossierStatique() . '/fr/index.html');

        $this->postAvecJeton('/cedric-taldu/admin/menu', ['menu_principal' => '[]']);

        // Plus un seul fichier : PHP sert tout jusqu'à la prochaine génération.
        $this->assertFileDoesNotExist($this->dossierStatique() . '/fr/index.html');
        $this->assertStringContainsString('Site statique périmé', $this->requete('GET', '/cedric-taldu/admin')->body);
    }

    public function test_la_generation_repond_en_json_a_la_barre(): void
    {
        $reponse = $this->postAvecJeton(self::GENERER, server: ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(200, $reponse->status);
        $donnees = json_decode($reponse->body, true);
        $this->assertIsArray($donnees);
        $this->assertSame(1, $donnees['number']);
        $this->assertGreaterThan(5, $donnees['count']);
        $this->assertIsArray($donnees['log']);
    }
}
