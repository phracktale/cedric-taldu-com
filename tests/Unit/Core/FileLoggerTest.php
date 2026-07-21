<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\FileLogger;
use App\Core\LogLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FrozenClock;

#[CoversClass(FileLogger::class)]
final class FileLoggerTest extends TestCase
{
    private string $repertoire;

    protected function setUp(): void
    {
        $this->repertoire = sys_get_temp_dir() . '/ct-logs-' . bin2hex(random_bytes(6));
        mkdir($this->repertoire, 0o770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->repertoire . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        rmdir($this->repertoire);
    }

    private function journal(): FileLogger
    {
        return new FileLogger($this->repertoire, new FrozenClock('2026-07-21 09:30:00'));
    }

    private function contenu(): string
    {
        $fichiers = glob($this->repertoire . '/*.log') ?: [];
        $this->assertCount(1, $fichiers, 'Un seul fichier de journal est attendu.');

        return (string) file_get_contents($fichiers[0]);
    }

    public function test_le_fichier_de_journal_est_date_du_jour(): void
    {
        // Rotation quotidienne, conservation 30 jours (09-environnements §8).
        $this->journal()->log(LogLevel::Info, 'démarrage');

        $this->assertFileExists($this->repertoire . '/app-2026-07-21.log');
    }

    public function test_une_ligne_porte_l_horodatage_le_niveau_et_le_message(): void
    {
        $this->journal()->log(LogLevel::Warning, 'jeton CSRF invalide');

        $ligne = $this->contenu();

        $this->assertStringContainsString('2026-07-21T09:30:00+00:00', $ligne);
        $this->assertStringContainsString('WARNING', $ligne);
        $this->assertStringContainsString('jeton CSRF invalide', $ligne);
    }

    public function test_le_contexte_est_serialise_en_json(): void
    {
        $this->journal()->log(LogLevel::Error, 'échec', ['route' => 'cart.add', 'statut' => 500]);

        $this->assertStringContainsString('{"route":"cart.add","statut":500}', $this->contenu());
    }

    public function test_chaque_entree_tient_sur_une_seule_ligne(): void
    {
        // Une entree multiligne casse tout traitement du journal et permet de
        // forger une fausse entree en injectant un saut de ligne dans un message.
        $this->journal()->log(LogLevel::Warning, "connexion refusée\n2026-01-01 ADMIN connexion réussie");

        $this->assertSame(1, substr_count($this->contenu(), "\n"));
    }

    public function test_un_saut_de_ligne_dans_le_contexte_est_neutralise(): void
    {
        $this->journal()->log(LogLevel::Info, 'message', ['agent' => "Mozilla\r\nX: y"]);

        $this->assertSame(1, substr_count($this->contenu(), "\n"));
    }

    public function test_deux_entrees_s_ajoutent_sans_ecraser_la_precedente(): void
    {
        $journal = $this->journal();

        $journal->log(LogLevel::Info, 'première');
        $journal->log(LogLevel::Info, 'seconde');

        $contenu = $this->contenu();
        $this->assertStringContainsString('première', $contenu);
        $this->assertStringContainsString('seconde', $contenu);
        $this->assertSame(2, substr_count($contenu, "\n"));
    }

    public function test_un_identifiant_de_correlation_est_rendu_pour_l_afficher_sur_la_page_500(): void
    {
        // 06-securite §10 : la page 500 affiche un identifiant de correlation et
        // rien d'autre. Il doit donc etre retrouvable dans le journal.
        $identifiant = $this->journal()->log(LogLevel::Error, 'exception non rattrapée');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $identifiant);
        $this->assertStringContainsString($identifiant, $this->contenu());
    }

    public function test_le_repertoire_de_journal_est_cree_s_il_manque(): void
    {
        $sousRepertoire = $this->repertoire . '/imbrique';
        $journal = new FileLogger($sousRepertoire, new FrozenClock('2026-07-21 09:30:00'));

        $journal->log(LogLevel::Info, 'message');

        $this->assertFileExists($sousRepertoire . '/app-2026-07-21.log');

        unlink($sousRepertoire . '/app-2026-07-21.log');
        rmdir($sousRepertoire);
    }
}
