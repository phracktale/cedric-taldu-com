<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Core\RandomInterface;
use App\Domain\Editorial\Block;
use App\Domain\Editorial\BlockCatalog;
use App\Service\Content\BlockSanitizer;
use App\Service\Content\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Modèles de section (retours du 2026-09-25) : des assemblages prêts à
 * insérer — bannière, 1, 2 ou 3 colonnes, texte + image, appel à l'action —
 * faits des blocs du catalogue, et qui passent l'assainisseur intacts.
 */
final class BlockPresetsTest extends TestCase
{
    public function test_les_modeles_de_section_proposes(): void
    {
        $this->assertSame(
            ['hero', 'section-1', 'section-2', 'section-3', 'media-text', 'cta'],
            array_keys(BlockCatalog::presets()),
        );
    }

    public function test_chaque_modele_survit_a_l_assainisseur_avec_sa_structure(): void
    {
        $sanitizer = new BlockSanitizer(new HtmlSanitizer(), new class implements RandomInterface {
            public function hex(int $bytes): string
            {
                return str_repeat('a', $bytes * 2);
            }
        });

        foreach (BlockCatalog::presets() as $cle => $modele) {
            $propre = json_decode($sanitizer->sanitizeJson(json_encode($modele['blocks'], JSON_THROW_ON_ERROR)), true);

            $this->assertSame(self::types($modele['blocks']), self::types($propre), $cle);
        }

        $deux = BlockCatalog::presets()['section-2']['blocks'];
        $this->assertSame('section', $deux[0]['type']);
        $this->assertSame('columns', $deux[0]['children'][0]['type']);
        $this->assertCount(2, $deux[0]['children'][0]['children']);
    }

    public function test_les_choix_de_design_ont_un_libelle_lisible(): void
    {
        $hauteur = BlockCatalog::definition('hero')['schema']['height'] ?? [];

        $this->assertSame('Haute', $hauteur['labels']['large'] ?? null);
    }

    public function test_les_images_de_fond_et_de_texte_image_sont_chargees(): void
    {
        $blocs = Block::listFromArray([
            ['type' => 'hero', 'props' => ['media' => '3']],
            ['type' => 'section', 'props' => ['backgroundMedia' => '4'], 'children' => [
                ['type' => 'media-text', 'props' => ['media' => '5']],
                ['type' => 'image', 'props' => ['media' => '6']],
            ]],
        ]);

        $this->assertSame([3, 4, 5, 6], Block::mediaIdsIn($blocs));
    }

    /**
     * @param array<mixed> $blocs
     * @return list<mixed>
     */
    private static function types(array $blocs): array
    {
        return array_map(
            static fn (array $b): array => [$b['type'], self::types($b['children'] ?? [])],
            array_values(array_filter($blocs, 'is_array')),
        );
    }
}
