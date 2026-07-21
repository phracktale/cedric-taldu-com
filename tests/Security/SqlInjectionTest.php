<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\SeriesFactory;
use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §1, et tests/CLAUDE.md : « Pour chaque champ texte de chaque
 * formulaire, injecte une charge SQL et verifie que la donnee est stockee TELLE
 * QUELLE et qu'aucune erreur SQL ne fuit. »
 *
 * Le lot 1 n'a pas encore de formulaire : la surface d'entree, ce sont les
 * segments d'URL et les parametres de requete. Ce sont eux qui sont eprouves
 * ici, plus la persistance d'une charge stockee telle quelle.
 */
final class SqlInjectionTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function chargesSql(): iterable
    {
        yield 'tautologie' => ["' OR '1'='1"];
        yield 'commentaire' => ["admin'--"];
        yield 'union' => ["' UNION SELECT NULL,NULL,NULL--"];
        yield 'requête empilée' => ["'; DROP TABLE artworks;--"];
        yield 'guillemet simple' => ["'"];
        yield 'antislash' => ['\\'];
        yield 'temporisation' => ["' AND SLEEP(5)--"];
        yield 'sous-requête' => ["1' AND (SELECT COUNT(*) FROM users)>0--"];
    }

    #[DataProvider('chargesSql')]
    public function test_une_charge_dans_un_segment_d_url_ne_produit_aucune_erreur(string $charge): void
    {
        $reponse = $this->get('/cedric-taldu/fr/oeuvre/' . rawurlencode($charge));

        // Refusee en amont : 404 par le format de slug, ou 400 quand le chemin
        // lui-meme est malforme — un antislash, par exemple, est rejete des la
        // construction de la requete. Jamais 500, jamais 200.
        $this->assertContains($reponse->status, [400, 404]);
        $this->assertStringNotContainsString('SQLSTATE', $reponse->body);
        $this->assertStringNotContainsString('SELECT', $reponse->body);
    }

    #[DataProvider('chargesSql')]
    public function test_une_charge_dans_un_filtre_de_serie_ne_produit_aucune_erreur(string $charge): void
    {
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $reponse = $this->get('/cedric-taldu/fr/galerie/encres?serie=' . rawurlencode($charge));

        $this->assertSame(200, $reponse->status);
        $this->assertStringNotContainsString('SQLSTATE', $reponse->body);
    }

    #[DataProvider('chargesSql')]
    public function test_une_charge_dans_la_pagination_ne_produit_aucune_erreur(string $charge): void
    {
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $reponse = $this->get('/cedric-taldu/fr/galerie/encres?page=' . rawurlencode($charge));

        $this->assertSame(200, $reponse->status);
        $this->assertStringNotContainsString('SQLSTATE', $reponse->body);
    }

    #[DataProvider('chargesSql')]
    public function test_une_charge_stockee_est_relue_telle_quelle(string $charge): void
    {
        // Le point essentiel : la donnee n'est ni tronquee, ni echappee en base,
        // ni interpretee. Elle est stockee telle quelle et ne s'echappe qu'a
        // l'affichage.
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->usingTechnique($charge)
            ->translated('fr', 'articulation', 'Articulation')->create($rubrique);

        $statement = $this->pdo->query('SELECT technique FROM artworks ORDER BY id DESC LIMIT 1');

        $this->assertNotFalse($statement);
        $this->assertSame($charge, $statement->fetchColumn());
    }

    public function test_une_requete_empilee_ne_supprime_aucune_table(): void
    {
        // La preuve que rien n'a ete execute : la table est encore la, et les
        // donnees aussi.
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->translated('fr', 'articulation', 'Articulation')->create($rubrique);
        (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($rubrique);

        foreach (self::chargesSql() as [$charge]) {
            $this->get('/cedric-taldu/fr/galerie/encres?serie=' . rawurlencode($charge));
        }

        $statement = $this->pdo->query('SELECT COUNT(*) FROM artworks');

        $this->assertNotFalse($statement);
        $this->assertSame(1, (int) $statement->fetchColumn());
    }

    public function test_aucune_page_ne_laisse_fuir_un_message_du_moteur(): void
    {
        // 06-securite §1 : « Les messages d'erreur SQL ne sortent jamais vers le
        // navigateur. » Un message de moteur renseigne un attaquant sur le
        // schema aussi surement qu'un acces direct.
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->translated('fr', 'articulation', 'Articulation')->create($rubrique);

        $pages = [
            '/cedric-taldu/fr/',
            '/cedric-taldu/fr/galerie/encres',
            '/cedric-taldu/fr/oeuvre/articulation',
            '/cedric-taldu/fr/inexistante',
        ];

        foreach ($pages as $page) {
            $corps = $this->get($page)->body;

            foreach (['SQLSTATE', 'PDOException', 'mysql', 'You have an error in your SQL'] as $fuite) {
                $this->assertStringNotContainsString($fuite, $corps, $page);
            }
        }
    }
}
