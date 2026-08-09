<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * 04-back-office §9 : édition des pages à code fixe.
 *
 * On n'y crée ni ne supprime : les cinq pages sont posées par la migration. Le
 * back-office édite leur contenu ; `legal`, `privacy` et `terms` restent
 * toujours accessibles.
 */
final class PagesAdminTest extends AdminTestCase
{
    private const PAGES = '/cedric-taldu/admin/pages';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_la_liste_montre_les_cinq_pages_fixes(): void
    {
        $reponse = $this->get(self::PAGES);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('À propos', $reponse->body);
        $this->assertStringContainsString('Conditions générales de vente', $reponse->body);
    }

    public function test_le_formulaire_d_edition_s_ouvre(): void
    {
        $reponse = $this->get(self::PAGES . '/' . $this->idDe('about'));

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="titre_fr"', $reponse->body);
        $this->assertStringContainsString('name="corps_fr"', $reponse->body);
    }

    public function test_l_edition_enregistre_le_contenu_assaini(): void
    {
        $this->postAvecJeton(self::PAGES . '/' . $this->idDe('about'), [
            'titre_fr' => 'À propos de Cédric',
            'corps_fr' => '<p>Peintre à Amiens.</p><script>alert(1)</script>',
        ]);

        $body = (string) $this->valeur(
            "SELECT t.body FROM page_translations t JOIN pages p ON p.id = t.page_id WHERE p.code = 'about' AND t.locale = 'fr'"
        );

        $this->assertStringContainsString('<p>Peintre à Amiens.</p>', $body);
        $this->assertStringNotContainsString('<script', $body);
    }

    public function test_le_contenu_edite_paraît_sur_la_page_publique(): void
    {
        $this->postAvecJeton(self::PAGES . '/' . $this->idDe('about'), [
            'titre_fr' => 'À propos de Cédric',
            'corps_fr' => '<p>Peintre plasticien à Amiens.</p>',
        ]);

        $reponse = $this->get('/cedric-taldu/fr/a-propos');

        $this->assertStringContainsString('À propos de Cédric', $reponse->body);
        $this->assertStringContainsString('Peintre plasticien à Amiens.', $reponse->body);
    }

    public function test_le_formulaire_porte_l_editeur_de_blocs(): void
    {
        $reponse = $this->get(self::PAGES . '/' . $this->idDe('about'));

        $this->assertStringContainsString('name="blocs_fr"', $reponse->body);
        $this->assertStringContainsString('data-block-editor', $reponse->body);
        $this->assertStringContainsString('data-catalog', $reponse->body);
    }

    public function test_l_enregistrement_de_blocs_les_stocke_assainis(): void
    {
        $blocs = json_encode([
            ['type' => 'heading', 'props' => ['text' => 'Mon parcours', 'level' => '2']],
            ['type' => 'text', 'props' => ['content' => '<p>Bio.</p><script>alert(1)</script>']],
            ['type' => 'evil', 'props' => []],
        ], JSON_THROW_ON_ERROR);

        $this->postAvecJeton(self::PAGES . '/' . $this->idDe('about'), [
            'titre_fr' => 'À propos',
            'blocs_fr' => $blocs,
        ]);

        $stocke = (string) $this->valeur(
            "SELECT t.blocks FROM page_translations t JOIN pages p ON p.id = t.page_id"
            . " WHERE p.code = 'about' AND t.locale = 'fr'"
        );

        /** @var list<array{type: string}> $blocks */
        $blocks = json_decode($stocke, true);
        $types = array_column($blocks, 'type');

        $this->assertContains('heading', $types);
        $this->assertNotContains('evil', $types, 'Un type inconnu ne doit pas être stocké.');
        $this->assertStringNotContainsString('<script', $stocke);
    }

    public function test_les_blocs_enregistres_paraissent_sur_la_page_publique(): void
    {
        $blocs = json_encode([
            ['type' => 'heading', 'props' => ['text' => 'Parcours et démarche', 'level' => '2']],
        ], JSON_THROW_ON_ERROR);

        $this->postAvecJeton(self::PAGES . '/' . $this->idDe('about'), [
            'titre_fr' => 'À propos',
            'corps_fr' => '<p>Ancien HTML.</p>',
            'blocs_fr' => $blocs,
        ]);

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        // Les blocs REMPLACENT le HTML historique.
        $this->assertStringContainsString('Parcours et démarche', $corps);
        $this->assertStringNotContainsString('Ancien HTML.', $corps);
    }

    public function test_des_blocs_vides_reviennent_au_contenu_html(): void
    {
        $this->postAvecJeton(self::PAGES . '/' . $this->idDe('about'), [
            'titre_fr' => 'À propos',
            'corps_fr' => '<p>Contenu HTML.</p>',
            'blocs_fr' => '[]',
        ]);

        $stocke = $this->valeur(
            "SELECT t.blocks FROM page_translations t JOIN pages p ON p.id = t.page_id"
            . " WHERE p.code = 'about' AND t.locale = 'fr'"
        );

        $this->assertNull($stocke);
        $this->assertStringContainsString('Contenu HTML.', $this->get('/cedric-taldu/fr/a-propos')->body);
    }

    public function test_un_identifiant_inexistant_repond_404(): void
    {
        $this->assertSame(404, $this->get(self::PAGES . '/999999')->status);
    }

    public function test_une_page_reglementaire_ne_peut_pas_etre_depubliee(): void
    {
        // 04-back-office §9 : legal/privacy/terms restent toujours accessibles.
        $this->postAvecJeton(self::PAGES . '/' . $this->idDe('terms') . '/publication');

        $publie = (int) $this->valeur("SELECT is_published FROM pages WHERE code = 'terms'");
        $this->assertSame(1, $publie);
    }

    private function idDe(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM pages WHERE code = :code');
        $statement->execute(['code' => $code]);

        return (int) $statement->fetchColumn();
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false || $valeur === null ? null : (string) $valeur;
    }
}
