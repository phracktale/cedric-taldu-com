<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\UploadedFile;
use App\Service\Fulfillment\Exception\PrintAssetRejected;
use App\Service\Fulfillment\PrintAssetStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\SequenceRandom;
use Tests\Support\ImageFixtures;

/**
 * Rangement du fichier d'impression haute définition (Prodigi).
 *
 * Contrairement à un média public, ce fichier N'EST PAS ré-encodé : l'impression
 * exige la pleine résolution et le profil colorimétrique d'origine. La source
 * est l'artiste authentifié, pas un visiteur — la validation reste stricte sur
 * le type et la taille, mais on conserve les octets tels quels.
 */
final class PrintAssetStoreTest extends TestCase
{
    private ImageFixtures $fixtures;
    private string $racine;
    private PrintAssetStore $store;

    protected function setUp(): void
    {
        $this->fixtures = new ImageFixtures();
        $this->racine = $this->fixtures->path('racine');
        mkdir($this->racine . '/storage/print', 0o775, true);

        $valeurs = [];
        for ($i = 1; $i <= 20; $i++) {
            $valeurs[] = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
        }

        $this->store = new PrintAssetStore(new SequenceRandom($valeurs), $this->racine . '/storage/print');
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();
    }

    private function televerse(string $chemin, ?string $nom = null): UploadedFile
    {
        return new UploadedFile($nom ?? basename($chemin), $chemin, (int) filesize($chemin));
    }

    public function test_un_jpeg_haute_definition_est_range_hors_webroot(): void
    {
        $asset = $this->store->store($this->televerse($this->fixtures->jpeg(2400, 1800)));

        $this->assertSame('image/jpeg', $asset->mime);
        $this->assertMatchesRegularExpression('#^print/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}\.jpg$#', $asset->relativePath);
        $this->assertFileExists($this->store->absolutePathFor($asset->relativePath));
    }

    public function test_les_octets_sont_conserves_tels_quels(): void
    {
        // La preuve que rien n'est ré-encodé : l'empreinte du fichier stocké est
        // celle du fichier reçu.
        $source = $this->fixtures->png(600, 400);
        $asset = $this->store->store($this->televerse($source));

        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $this->store->absolutePathFor($asset->relativePath)));
    }

    public function test_un_type_non_imprimable_est_refuse(): void
    {
        // Prodigi n'accepte que JPEG, PNG et PDF : un fichier texte n'a rien a y faire.
        $txt = $this->fixtures->path('note.txt');
        file_put_contents($txt, "ceci n'est pas une image");

        $this->expectException(PrintAssetRejected::class);

        $this->store->store($this->televerse($txt));
    }

    public function test_supprimer_un_fichier_l_emporte(): void
    {
        $asset = $this->store->store($this->televerse($this->fixtures->jpeg()));
        $absolu = $this->store->absolutePathFor($asset->relativePath);
        $this->assertFileExists($absolu);

        $this->store->remove($asset->relativePath);

        $this->assertFileDoesNotExist($absolu);
    }
}
