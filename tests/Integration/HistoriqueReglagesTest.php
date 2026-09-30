<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Admin\RevisionRepository;
use App\Repository\Admin\SettingsAdminRepository;
use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Historique des réglages (demande du 2026-09-30) : chaque écriture d'un
 * réglage garde la version PRÉCÉDENTE, pour réparer une fausse manipulation —
 * un bloc retiré d'un template, une mise en page d'accueil cassée.
 */
final class HistoriqueReglagesTest extends DatabaseTestCase
{
    private RevisionRepository $revisions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->revisions = new RevisionRepository($this->pdo);
    }

    public function test_enregistrer_un_reglage_garde_la_version_precedente(): void
    {
        $reglages = $this->reglages();

        $reglages->save('template.post', [['type' => 'header'], ['type' => 'body']], $this->maintenant());
        $reglages->save('template.post', [['type' => 'body']], $this->maintenant());

        $versions = $this->revisions->history('setting', 'template.post');
        $this->assertCount(1, $versions);
        $this->assertSame('update', $versions[0]['action']);
        $this->assertSame(
            [['type' => 'header'], ['type' => 'body']],
            $this->revisions->find($versions[0]['id'])['snapshot'] ?? null,
        );
    }

    public function test_un_premier_enregistrement_n_a_rien_a_garder(): void
    {
        $this->reglages()->save('home.layout', ['a' => 1], $this->maintenant());

        $this->assertSame([], $this->revisions->history('setting', 'home.layout'));
    }

    public function test_un_enregistrement_identique_ne_cree_pas_de_version(): void
    {
        $reglages = $this->reglages();

        $reglages->save('home.layout', ['a' => 1], $this->maintenant());
        $reglages->save('home.layout', ['a' => 1], $this->maintenant());

        $this->assertSame([], $this->revisions->history('setting', 'home.layout'));
    }

    public function test_l_etat_de_la_generation_statique_n_est_pas_versionne(): void
    {
        // Écrit par la machine à chaque génération : ce n'est pas un contenu.
        $reglages = $this->reglages();

        $reglages->save('static.generation', ['n' => 1], $this->maintenant());
        $reglages->save('static.generation', ['n' => 2], $this->maintenant());

        $this->assertSame([], $this->revisions->history('setting', 'static.generation'));
    }

    public function test_l_auteur_de_la_modification_est_retenu(): void
    {
        $auteur = (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $reglages = new SettingsAdminRepository($this->pdo, $this->revisions, static fn (): int => $auteur);

        $reglages->save('home.layout', ['a' => 1], $this->maintenant());
        $reglages->save('home.layout', ['a' => 2], $this->maintenant());

        $this->assertSame('artiste@example.test', $this->revisions->history('setting', 'home.layout')[0]['author']);
    }

    public function test_sans_historique_branche_le_depot_se_comporte_comme_avant(): void
    {
        $reglages = new SettingsAdminRepository($this->pdo);

        $reglages->save('home.layout', ['a' => 1], $this->maintenant());
        $reglages->save('home.layout', ['a' => 2], $this->maintenant());

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM revisions')->fetchColumn());
    }

    private function reglages(): SettingsAdminRepository
    {
        return new SettingsAdminRepository($this->pdo, $this->revisions, static fn (): ?int => null);
    }

    private function maintenant(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-30 12:00:00');
    }
}
