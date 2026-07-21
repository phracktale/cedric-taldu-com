<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Route;
use Tests\Support\Doubles\ControleurQuiEchoue;
use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §10 : « En mode production, une exception forcee ne renvoie ni
 * trace, ni SQL, ni chemin serveur. La page 500 affiche un identifiant de
 * correlation et rien d'autre. »
 */
final class ErrorLeakTest extends FunctionalTestCase
{
    private const SECRET_DU_MESSAGE = 'mysql:host=db;dbname=cedrictaldu';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withRoutes([
            new Route('home', 'GET', '/fr/', [ControleurQuiEchoue::class, 'index'], locale: 'fr'),
        ]);

        $this->withService(
            ControleurQuiEchoue::class,
            static fn (): ControleurQuiEchoue => new ControleurQuiEchoue(),
        );
    }

    private function reponseEnProduction(): \App\Core\Response
    {
        $this->withEnv(['APP_ENV' => 'prod', 'APP_DEBUG' => '1']);

        return $this->get('/cedric-taldu/fr/');
    }

    public function test_une_exception_non_rattrapee_repond_500(): void
    {
        $this->assertSame(500, $this->reponseEnProduction()->status);
    }

    public function test_la_page_500_ne_contient_pas_le_message_d_exception(): void
    {
        $corps = $this->reponseEnProduction()->body;

        $this->assertStringNotContainsString(self::SECRET_DU_MESSAGE, $corps);
        $this->assertStringNotContainsString(ControleurQuiEchoue::MESSAGE, $corps);
    }

    public function test_la_page_500_ne_contient_ni_trace_ni_chemin_serveur(): void
    {
        $corps = $this->reponseEnProduction()->body;

        $this->assertStringNotContainsString('#0 ', $corps);
        $this->assertStringNotContainsString('Stack trace', $corps);
        $this->assertStringNotContainsString(ControleurQuiEchoue::class, $corps);
        $this->assertStringNotContainsString('/var/www', $corps);
        $this->assertStringNotContainsString('C:\\', $corps);
        $this->assertStringNotContainsString('.php', $corps);
    }

    public function test_la_page_500_ne_contient_aucun_fragment_de_sql(): void
    {
        $corps = $this->reponseEnProduction()->body;

        foreach (['SELECT', 'INSERT', 'UPDATE ', 'DELETE', 'mysql:', 'PDOException', 'SQLSTATE'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $corps);
        }
    }

    public function test_la_page_500_affiche_un_identifiant_de_correlation(): void
    {
        // C'est la seule information transmise a l'utilisateur : sans elle, un
        // incident signale par un visiteur est introuvable dans le journal.
        $corps = $this->reponseEnProduction()->body;

        $this->assertMatchesRegularExpression('/<code>[0-9a-f]{16}<\/code>/', $corps);
    }

    public function test_l_identifiant_affiche_est_celui_du_journal(): void
    {
        $corps = $this->reponseEnProduction()->body;

        preg_match('/<code>([0-9a-f]{16})<\/code>/', $corps, $trouve);

        $this->assertCount(2, $trouve);
        $this->assertCount(1, $this->logger->entries);
        $this->assertSame(self::SECRET_DU_MESSAGE, $this->logger->entries[0]['message']);
    }

    public function test_le_debogage_reste_desactive_en_production_meme_si_on_le_demande(): void
    {
        // Config force debug a false des que APP_ENV vaut prod : une erreur de
        // deploiement ne doit pas suffire a faire fuir une trace.
        $this->withEnv(['APP_ENV' => 'prod', 'APP_DEBUG' => 'true']);

        $this->assertStringNotContainsString(ControleurQuiEchoue::MESSAGE, $this->get('/cedric-taldu/fr/')->body);
    }

    public function test_en_developpement_le_detail_est_affiche(): void
    {
        // Le pendant du test precedent : hors production, on veut voir l'erreur,
        // sinon le mode developpement ne sert a rien.
        $this->withEnv(['APP_ENV' => 'dev', 'APP_DEBUG' => '1']);

        $this->assertStringContainsString(ControleurQuiEchoue::MESSAGE, $this->get('/cedric-taldu/fr/')->body);
    }

    public function test_la_page_500_porte_les_en_tetes_de_securite(): void
    {
        $reponse = $this->reponseEnProduction();

        $this->assertNotNull($reponse->header('Content-Security-Policy'));
        $this->assertSame('nosniff', $reponse->header('X-Content-Type-Options'));
    }

    public function test_la_page_500_n_est_pas_indexable(): void
    {
        $this->assertStringContainsString('name="robots" content="noindex"', $this->reponseEnProduction()->body);
    }

    public function test_l_amorcage_lui_meme_est_couvert_par_un_filet(): void
    {
        // Regression : un chemin de traversee est refuse par Request AVANT que
        // le noyau n'existe. Sans le try/catch de public/index.php, PHP
        // repondait 200 avec la trace complete et les chemins serveur — un
        // defaut invisible pour les tests fonctionnels, qui entrent tous par
        // Kernel::handle().
        $amorcage = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        $this->assertStringContainsString('try {', $amorcage);
        $this->assertStringContainsString('catch (Throwable', $amorcage);
        $this->assertStringContainsString('FailSafeResponse::for(', $amorcage);
        $this->assertStringContainsString('Request::fromGlobals', $amorcage);

        // La construction de la requete doit se trouver DANS le bloc protege.
        $this->assertLessThan(
            strpos($amorcage, 'catch (Throwable') ?: PHP_INT_MAX,
            strpos($amorcage, 'Request::fromGlobals') ?: 0,
        );
        $this->assertGreaterThan(
            strpos($amorcage, 'try {') ?: PHP_INT_MAX,
            strpos($amorcage, 'Request::fromGlobals') ?: 0,
        );
    }
}
