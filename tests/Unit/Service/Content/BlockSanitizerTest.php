<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Content;

use App\Service\Content\BlockSanitizer;
use App\Service\Content\HtmlSanitizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\SequenceRandom;

/**
 * Assainissement des blocs À L'ÉCRITURE.
 *
 * Le JSON vient d'un formulaire : on ne garde que les types connus et les props
 * déclarées, et on assainit chaque prop selon son type. C'est la barrière XSS du
 * back-office éditorial.
 */
final class BlockSanitizerTest extends TestCase
{
    private function sanitizer(): BlockSanitizer
    {
        // Ids fournis dans les tests : la séquence n'est consommée qu'en secours.
        return new BlockSanitizer(new HtmlSanitizer(), new SequenceRandom(['deadbeef', 'cafe', 'f00d']));
    }

    /** @return array<mixed> */
    private function parse(string $json): array
    {
        return json_decode($json, true);
    }

    public function test_un_type_inconnu_est_rejete(): void
    {
        $json = $this->sanitizer()->sanitizeJson(
            '[{"id":"a","type":"evil","props":{}},{"id":"b","type":"heading","props":{"text":"Ok"}}]'
        );

        $blocks = $this->parse($json);

        $this->assertCount(1, $blocks);
        $this->assertSame('heading', $blocks[0]['type']);
    }

    public function test_le_richtext_est_assaini(): void
    {
        $json = $this->sanitizer()->sanitizeJson(
            '[{"id":"a","type":"text","props":{"content":"<p>Bonjour<script>alert(1)</script></p>"}}]'
        );

        $content = $this->parse($json)[0]['props']['content'];

        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringContainsString('Bonjour', $content);
    }

    public function test_une_valeur_de_select_hors_liste_retombe_au_defaut(): void
    {
        $json = $this->sanitizer()->sanitizeJson('[{"id":"a","type":"heading","props":{"text":"T","level":"9"}}]');

        $this->assertSame('2', $this->parse($json)[0]['props']['level']);
    }

    public function test_une_url_de_bouton_hostile_est_videe(): void
    {
        $json = $this->sanitizer()->sanitizeJson(
            '[{"id":"a","type":"button","props":{"label":"X","url":"javascript:alert(1)"}}]'
        );

        $this->assertSame('', $this->parse($json)[0]['props']['url']);
    }

    public function test_les_props_non_declarees_sont_ecartees(): void
    {
        $json = $this->sanitizer()->sanitizeJson('[{"id":"a","type":"heading","props":{"text":"T","evil":"x"}}]');

        $props = $this->parse($json)[0]['props'];

        $this->assertArrayHasKey('text', $props);
        $this->assertArrayNotHasKey('evil', $props);
    }

    public function test_les_enfants_ne_survivent_que_sous_un_conteneur(): void
    {
        // 'heading' n'accepte pas d'enfants : ils sont supprimés. 'columns' oui.
        $json = $this->sanitizer()->sanitizeJson(
            '[{"id":"a","type":"heading","props":{"text":"T"},"children":[{"id":"c","type":"text","props":{}}]},'
            . '{"id":"b","type":"columns","props":{},"children":[{"id":"d","type":"text","props":{"content":"<p>Hi</p>"}}]}]'
        );

        $blocks = $this->parse($json);

        $this->assertArrayNotHasKey('children', $blocks[0]);
        $this->assertCount(1, $blocks[1]['children']);
    }

    public function test_un_json_malforme_donne_un_document_vide(): void
    {
        $this->assertSame('[]', $this->sanitizer()->sanitizeJson('pas du json'));
        $this->assertSame('[]', $this->sanitizer()->sanitizeJson(null));
    }

    public function test_chaque_bloc_porte_un_id_et_une_version(): void
    {
        // Bloc sans id : un identifiant de secours est forgé.
        $json = $this->sanitizer()->sanitizeJson('[{"type":"text","props":{"content":"<p>x</p>"}}]');

        $block = $this->parse($json)[0];

        $this->assertSame('deadbeef', $block['id']);
        $this->assertSame(1, $block['version']);
    }
}
