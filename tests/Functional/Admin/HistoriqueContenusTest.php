<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\PostFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Historique des contenus, incrément 2 (demande du 2026-09-30) : pages et
 * actus. Une modification garde le contenu précédent ; une actu supprimée
 * se restaure sous le même identifiant, publiée comme elle l'était.
 */
final class HistoriqueContenusTest extends AdminTestCase
{
    private const HISTORIQUE = '/cedric-taldu/admin/historique';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_une_page_modifiee_se_restaure(): void
    {
        $id = $this->idDePage('about');
        $this->postAvecJeton('/cedric-taldu/admin/pages/' . $id, ['titre_fr' => 'À propos', 'corps_fr' => '<p>Version un.</p>']);
        $this->postAvecJeton('/cedric-taldu/admin/pages/' . $id, ['titre_fr' => 'À propos', 'corps_fr' => '<p>Version deux.</p>']);

        $this->assertStringContainsString('Page « À propos »', $this->get(self::HISTORIQUE)->body);

        $this->postAvecJeton(self::HISTORIQUE . '/' . $this->derniereVersion('page', (string) $id) . '/restaurer');

        $this->assertStringContainsString(
            'Version un.',
            (string) $this->valeur("SELECT body FROM page_translations WHERE page_id = {$id} AND locale = 'fr'"),
        );
    }

    public function test_une_actu_modifiee_se_restaure_avec_ses_champs_d_exposition(): void
    {
        $this->postAvecJeton('/cedric-taldu/admin/actus', [
            'titre_fr' => 'Vernissage', 'corps_fr' => '<p>Premier texte.</p>',
            'date_evenement' => '2026-10-12', 'lieu_evenement' => 'Galerie du Beffroi',
        ]);
        $id = (int) $this->valeur('SELECT MAX(id) FROM posts');
        $this->postAvecJeton('/cedric-taldu/admin/actus/' . $id, ['titre_fr' => 'Vernissage', 'corps_fr' => '<p>Texte revu.</p>']);

        $this->assertStringContainsString('Actu « Vernissage »', $this->get(self::HISTORIQUE)->body);

        $this->postAvecJeton(self::HISTORIQUE . '/' . $this->derniereVersion('post', (string) $id) . '/restaurer');

        $this->assertStringContainsString('Premier texte.', (string) $this->valeur("SELECT body FROM post_translations WHERE post_id = {$id}"));
        $this->assertSame('Galerie du Beffroi', $this->valeur("SELECT event_place FROM posts WHERE id = {$id}"));
    }

    public function test_une_actu_supprimee_se_restaure_publiee_comme_avant(): void
    {
        $id = (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'vernissage', 'Vernissage', 'Extrait', '<p>Le corps.</p>')->create();

        $this->postAvecJeton('/cedric-taldu/admin/actus/' . $id . '/suppression');
        $this->assertSame('0', $this->valeur('SELECT COUNT(*) FROM posts'));
        $this->assertStringContainsString('Supprimé', $this->get(self::HISTORIQUE . '/post/' . $id)->body);

        $this->postAvecJeton(self::HISTORIQUE . '/' . $this->derniereVersion('post', (string) $id) . '/restaurer');

        $this->assertSame((string) $id, $this->valeur('SELECT id FROM posts'));
        $this->assertStringContainsString('Le corps.', $this->get('/cedric-taldu/fr/actus/vernissage')->body);
    }

    public function test_un_enregistrement_sans_changement_ne_cree_pas_de_version(): void
    {
        $this->postAvecJeton('/cedric-taldu/admin/actus', ['titre_fr' => 'Vernissage', 'corps_fr' => '<p>Texte.</p>']);
        $id = (int) $this->valeur('SELECT MAX(id) FROM posts');

        $this->postAvecJeton('/cedric-taldu/admin/actus/' . $id, ['titre_fr' => 'Vernissage', 'corps_fr' => '<p>Texte.</p>']);

        $this->assertSame('0', $this->valeur('SELECT COUNT(*) FROM revisions'));
    }

    // ------------------------------------------------------------- outils

    private function idDePage(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM pages WHERE code = :c');
        $statement->execute(['c' => $code]);

        return (int) $statement->fetchColumn();
    }

    private function derniereVersion(string $type, string $cle): int
    {
        $statement = $this->pdo->prepare('SELECT MAX(id) FROM revisions WHERE subject_type = :t AND subject_key = :k');
        $statement->execute(['t' => $type, 'k' => $cle]);

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
