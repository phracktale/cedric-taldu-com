<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Blocs en back-office (revue du 2026-09-24, incrément 2) : blocs sur les
 * actualités, sélecteur d'images de la médiathèque.
 */
final class BlocsAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_le_formulaire_d_actualite_propose_l_editeur_de_blocs(): void
    {
        $corps = $this->requete('GET', '/cedric-taldu/admin/actus/nouvel-article')->body;

        $this->assertMatchesRegularExpression('~name="blocs_fr"[^>]*data-block-editor~s', $corps);
        $this->assertStringContainsString('data-media-picker="/cedric-taldu/admin/medias/choix"', $corps);
    }

    public function test_les_blocs_d_une_actualite_sont_assainis_et_enregistres(): void
    {
        $this->postAvecJeton('/cedric-taldu/admin/actus', [
            'titre_fr' => 'Vernissage',
            'blocs_fr' => json_encode([
                ['type' => 'evil', 'props' => []],
                ['type' => 'text', 'props' => ['content' => '<p>Bonjour</p><script>alert(1)</script>']],
            ], JSON_THROW_ON_ERROR),
        ]);

        $stocke = (string) $this->pdo->query('SELECT blocks FROM post_translations')->fetchColumn();

        $this->assertStringContainsString('Bonjour', $stocke);
        $this->assertStringNotContainsString('evil', $stocke);
        $this->assertStringNotContainsString('<script>', $stocke);
    }

    public function test_le_selecteur_liste_les_images_avec_leur_vignette(): void
    {
        $media = (new MediaFactory($this->pdo))->named('choix-atelier')->translated('fr', 'Atelier')->create();

        $reponse = $this->requete('GET', '/cedric-taldu/admin/medias/choix');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('application/json', (string) ($reponse->headers['content-type'] ?? ''));
        /** @var array{medias: list<array{id: int, label: string, thumb: string}>} $reponseJson */
        $reponseJson = json_decode($reponse->body, true, 8, JSON_THROW_ON_ERROR);
        $liste = $reponseJson['medias'];
        $this->assertSame($media, $liste[0]['id']);
        $this->assertSame('Atelier', $liste[0]['label']);
        $this->assertStringStartsWith('/cedric-taldu/', $liste[0]['thumb']);
        $this->assertStringContainsString('choix-atelier', $liste[0]['thumb']);
    }
}
