<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\UploadedFile;
use App\Service\Fulfillment\PrintAssetStore;
use App\Service\Fulfillment\PrintAssetUrl;
use Tests\Support\Doubles\SequenceRandom;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;
use Tests\Support\ImageFixtures;

/**
 * Route à jeton servant le fichier d'impression au robot de Prodigi.
 *
 * Le fichier vit hors webroot : seule cette route, munie d'un jeton signé, y
 * donne accès. Un jeton forgé ou une œuvre sans fichier restent introuvables.
 */
final class PrintAssetTest extends FunctionalTestCase
{
    private const SECRET = 'poivre-de-test-impression';

    private ImageFixtures $fixtures;
    private int $artwork;
    private string $relativePath;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new ImageFixtures();
        $racine = $this->fixtures->path('impr');
        mkdir($racine . '/storage/print', 0o775, true);

        $valeurs = [];
        for ($i = 1; $i <= 6; $i++) {
            $valeurs[] = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
        }
        $store = new PrintAssetStore(new SequenceRandom($valeurs), $racine . '/storage/print');

        $this->source = $this->fixtures->jpeg(1200, 900, 'print.jpg');
        $asset = $store->store(new UploadedFile('print.jpg', $this->source, (int) filesize($this->source)));
        $this->relativePath = $asset->relativePath;

        $this->withService(PrintAssetStore::class, fn (): PrintAssetStore => $store);
        $this->withService(PrintAssetUrl::class, fn (): PrintAssetUrl => new PrintAssetUrl(self::SECRET));

        $categorie = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $this->artwork = (new ArtworkFactory($this->pdo))->published()
            ->translated('fr', 'articulation', 'Articulation')->create($categorie);

        $this->pdo->prepare(
            'UPDATE artworks SET print_asset_path = :p, print_asset_mime = :m WHERE id = :id'
        )->execute(['p' => $this->relativePath, 'm' => 'image/jpeg', 'id' => $this->artwork]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->fixtures->cleanup();
    }

    public function test_un_jeton_valide_sert_le_fichier_avec_son_type(): void
    {
        $token = (new PrintAssetUrl(self::SECRET))->token($this->artwork);

        $reponse = $this->get('/cedric-taldu/impression/' . $token);

        $this->assertSame(200, $reponse->status);
        $this->assertSame('image/jpeg', $reponse->header('Content-Type'));
        $this->assertSame((string) file_get_contents($this->source), $reponse->body);
    }

    public function test_un_jeton_forge_reste_introuvable(): void
    {
        // Bon format, mauvaise signature : la route matche, le contrôleur refuse.
        $reponse = $this->get('/cedric-taldu/impression/' . $this->artwork . '.' . str_repeat('0', 32));

        $this->assertSame(404, $reponse->status);
    }

    public function test_une_oeuvre_sans_fichier_reste_introuvable(): void
    {
        $this->pdo->prepare('UPDATE artworks SET print_asset_path = NULL WHERE id = :id')
            ->execute(['id' => $this->artwork]);

        $token = (new PrintAssetUrl(self::SECRET))->token($this->artwork);

        $this->assertSame(404, $this->get('/cedric-taldu/impression/' . $token)->status);
    }
}
