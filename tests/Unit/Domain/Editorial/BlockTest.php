<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\Block;
use PHPUnit\Framework\TestCase;

/**
 * Bloc éditorial au format d'échange editor-core.
 *
 * Le parseur est défensif : le JSON vient de la base et un document malformé ne
 * doit jamais casser une page.
 */
final class BlockTest extends TestCase
{
    public function test_un_document_json_devient_une_liste_de_blocs(): void
    {
        $blocks = Block::listFromJson('[{"type":"heading","props":{"text":"Salut","level":"2"}}]');

        $this->assertCount(1, $blocks);
        $this->assertSame('heading', $blocks[0]->type);
        $this->assertSame('Salut', $blocks[0]->text('text'));
        $this->assertSame('2', $blocks[0]->text('level'));
    }

    public function test_les_blocs_conteneurs_portent_leurs_enfants(): void
    {
        $json = '[{"type":"columns","props":{"count":"2"},"children":['
            . '{"type":"text","props":{"content":"<p>A</p>"}},'
            . '{"type":"text","props":{"content":"<p>B</p>"}}]}]';

        $blocks = Block::listFromJson($json);

        $this->assertCount(2, $blocks[0]->children);
        $this->assertSame('text', $blocks[0]->children[0]->type);
    }

    public function test_un_bloc_sans_type_est_ignore(): void
    {
        $blocks = Block::listFromJson('[{"props":{"x":1}},{"type":"text","props":{}}]');

        $this->assertCount(1, $blocks);
        $this->assertSame('text', $blocks[0]->type);
    }

    public function test_un_json_vide_ou_malforme_donne_une_liste_vide(): void
    {
        $this->assertSame([], Block::listFromJson(null));
        $this->assertSame([], Block::listFromJson(''));
        $this->assertSame([], Block::listFromJson('pas du json'));
        $this->assertSame([], Block::listFromJson('{"type":"text"}')); // objet, pas liste
    }

    public function test_une_prop_absente_retombe_sur_le_defaut(): void
    {
        $block = Block::listFromJson('[{"type":"heading","props":{}}]')[0];

        $this->assertSame('2', $block->text('level', '2'));
        $this->assertSame('', $block->text('text'));
    }
}
