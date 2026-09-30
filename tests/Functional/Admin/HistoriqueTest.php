<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Paramètres › Historique (demande du 2026-09-30) : toute modification de la
 * structure (templates, accueil…) et des blocs réutilisables garde la version
 * précédente ; on la consulte, on la restaure, et l'on purge l'historique
 * derrière une alerte où il faut TAPER « PURGER ».
 */
final class HistoriqueTest extends AdminTestCase
{
    private const HISTORIQUE = '/cedric-taldu/admin/historique';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_un_template_modifie_apparait_dans_l_historique(): void
    {
        $this->template([['type' => 'header'], ['type' => 'body']]);
        $this->template([['type' => 'body']]);

        $liste = $this->get(self::HISTORIQUE)->body;
        $this->assertStringContainsString('Template « Actualité »', $liste);

        $fiche = $this->get(self::HISTORIQUE . '/setting/template.post')->body;
        $this->assertSame(1, substr_count($fiche, 'Restaurer cette version'));
        $this->assertStringContainsString('artiste@example.test', $fiche);
    }

    public function test_restaurer_remet_le_template_et_garde_l_etat_remplace(): void
    {
        $this->template([['type' => 'header'], ['type' => 'body']]);
        $this->template([['type' => 'body']]);
        $version = $this->derniereVersion('setting', 'template.post');

        $reponse = $this->postAvecJeton(self::HISTORIQUE . '/' . $version . '/restaurer');

        $this->assertSame(302, $reponse->status);
        $this->assertSame(
            ['header', 'body'],
            array_column($this->reglage('template.post'), 'type'),
        );
        // La restauration se versionne elle-même : on peut revenir en arrière.
        $this->assertSame(2, $this->compterVersions('setting', 'template.post'));
        $this->assertSame(
            'restore',
            $this->valeur("SELECT action FROM revisions WHERE subject_key = 'template.post' ORDER BY id DESC LIMIT 1"),
        );
    }

    public function test_un_bloc_reutilisable_modifie_se_restaure(): void
    {
        $id = $this->creerBloc('Bannière');
        $this->postAvecJeton('/cedric-taldu/admin/blocs/' . $id, ['nom' => 'Bannière revue', 'blocs_fr' => '[]', 'blocs_en' => '']);

        $this->postAvecJeton(self::HISTORIQUE . '/' . $this->derniereVersion('content_block', (string) $id) . '/restaurer');

        $this->assertSame('Bannière', $this->valeur('SELECT name FROM content_blocks WHERE id = ' . $id));
    }

    public function test_un_bloc_reutilisable_supprime_se_restaure(): void
    {
        $id = $this->creerBloc('Bannière');
        $this->postAvecJeton('/cedric-taldu/admin/blocs/' . $id . '/suppression');
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM content_blocks'));

        $fiche = $this->get(self::HISTORIQUE . '/content_block/' . $id)->body;
        $this->assertStringContainsString('Supprimé', $fiche);

        $this->postAvecJeton(self::HISTORIQUE . '/' . $this->derniereVersion('content_block', (string) $id) . '/restaurer');

        $this->assertSame('Bannière', $this->valeur('SELECT name FROM content_blocks WHERE id = ' . $id));
    }

    public function test_l_ecran_previent_que_la_purge_est_irreversible(): void
    {
        $corps = $this->get(self::HISTORIQUE)->body;

        $this->assertStringContainsString('irréversible', $corps);
        $this->assertStringContainsString('name="confirmation"', $corps);
        $this->assertStringContainsString('PURGER', $corps);
    }

    public function test_la_purge_sans_taper_purger_est_refusee(): void
    {
        $this->template([['type' => 'header']]);
        $this->template([['type' => 'body']]);

        foreach (['', 'purger', 'PURGE', ' PURGER x'] as $saisie) {
            $reponse = $this->postAvecJeton(self::HISTORIQUE . '/purge', ['portee' => 'tout', 'confirmation' => $saisie]);

            $this->assertSame(422, $reponse->status, $saisie);
            $this->assertStringContainsString('Tapez PURGER', $reponse->body);
        }

        $this->assertSame(1, $this->compterVersions('setting', 'template.post'));
    }

    public function test_la_purge_confirmee_efface_tout_l_historique(): void
    {
        $this->template([['type' => 'header']]);
        $this->template([['type' => 'body']]);

        $reponse = $this->postAvecJeton(self::HISTORIQUE . '/purge', ['portee' => 'tout', 'confirmation' => 'PURGER']);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(0, (int) $this->valeur('SELECT COUNT(*) FROM revisions'));
        // La purge laisse une trace au journal d'audit.
        $this->assertSame(1, (int) $this->valeur("SELECT COUNT(*) FROM audit_log WHERE action = 'revisions.purge'"));
    }

    public function test_la_purge_des_anciennes_versions_garde_les_recentes(): void
    {
        $this->template([['type' => 'header']]);
        $this->template([['type' => 'body']]);
        $this->template([['type' => 'cover']]);
        $this->pdo->exec(
            "UPDATE revisions SET created_at = '2020-01-01 00:00:00' ORDER BY id LIMIT 1"
        );

        $this->postAvecJeton(self::HISTORIQUE . '/purge', ['portee' => 'anciennes', 'jours' => '30', 'confirmation' => 'PURGER']);

        $this->assertSame(1, (int) $this->valeur('SELECT COUNT(*) FROM revisions'));
    }

    // ------------------------------------------------------------- outils

    /**
     * @param list<array<string, string>> $sections
     */
    private function template(array $sections): void
    {
        $this->assertSame(302, $this->postAvecJeton('/cedric-taldu/admin/templates', [
            'template_post' => json_encode($sections, JSON_THROW_ON_ERROR),
        ])->status);
    }

    private function creerBloc(string $nom): int
    {
        $this->postAvecJeton('/cedric-taldu/admin/blocs', ['nom' => $nom, 'modele' => '']);

        return (int) $this->valeur('SELECT MAX(id) FROM content_blocks');
    }

    private function derniereVersion(string $type, string $cle): int
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(id) FROM revisions WHERE subject_type = :t AND subject_key = :k'
        );
        $statement->execute(['t' => $type, 'k' => $cle]);

        return (int) $statement->fetchColumn();
    }

    private function compterVersions(string $type, string $cle): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM revisions WHERE subject_type = :t AND subject_key = :k'
        );
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

    /**
     * @return list<array<string, mixed>>
     */
    private function reglage(string $cle): array
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE `key` = :k');
        $statement->execute(['k' => $cle]);

        /** @var list<array<string, mixed>> $valeur */
        $valeur = json_decode((string) $statement->fetchColumn(), true);

        return $valeur;
    }
}
