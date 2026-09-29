<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\PostFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Médiathèque : où une image est-elle utilisée ? (retour client du 2026-09-29,
 * point 14). L'écran ne donnait qu'un nombre : l'artiste ne pouvait ni trouver
 * ni retirer les usages qui bloquaient la suppression. Chaque usage est
 * désormais nommé, avec un lien vers l'écran où le retirer.
 */
final class MediasUsagesTest extends AdminTestCase
{
    private const MEDIAS = '/cedric-taldu/admin/medias';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_chaque_usage_est_nomme_avec_son_lien(): void
    {
        $media = (new MediaFactory($this->pdo))->named('encre')->create();
        $galerie = (new CategoryFactory($this->pdo))->withCover($media)->translated('fr', 'encres', 'Encres')->create();
        $oeuvre = (new ArtworkFactory($this->pdo))->withPrimaryMedia($media)->withReference('CT-001')
            ->translated('fr', 'pilier', 'Pilier')->create($galerie);
        $actu = (new PostFactory($this->pdo))->withCover($media)->translated('fr', 'vernissage', 'Vernissage')->create();
        $apropos = (int) $this->pdo->query("SELECT id FROM pages WHERE code = 'about'")->fetchColumn();
        $this->pdo->prepare("UPDATE page_translations SET blocks = :b WHERE page_id = :p AND locale = 'fr'")->execute([
            'b' => json_encode([['type' => 'image', 'props' => ['media' => (string) $media]]], JSON_THROW_ON_ERROR),
            'p' => $apropos,
        ]);

        $fiche = $this->requete('GET', self::MEDIAS . '/' . $media)->body;

        $this->assertStringContainsString('Utilisée par', $fiche);
        $this->assertStringContainsString('<a href="/cedric-taldu/admin/oeuvres/' . $oeuvre . '">Œuvre « Pilier » (CT-001) — image principale</a>', $fiche);
        $this->assertStringContainsString('<a href="/cedric-taldu/admin/galeries/' . $galerie . '">Galerie « Encres » — couverture</a>', $fiche);
        $this->assertStringContainsString('<a href="/cedric-taldu/admin/actus/' . $actu . '">Actu « Vernissage » — couverture</a>', $fiche);
        $this->assertStringContainsString('<a href="/cedric-taldu/admin/pages/' . $apropos . '">Page « À propos » — bloc image</a>', $fiche);
    }

    public function test_une_oeuvre_sans_titre_est_reperable_par_sa_reference(): void
    {
        // « Des œuvres vides » : sans titre, l'œuvre reste nommée par sa référence.
        $media = (new MediaFactory($this->pdo))->named('vide')->create();
        $galerie = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $oeuvre = (new ArtworkFactory($this->pdo))->withPrimaryMedia($media)->withReference('CT-VIDE')->create($galerie);
        $this->pdo->prepare('DELETE FROM artwork_translations WHERE artwork_id = :id')->execute(['id' => $oeuvre]);

        $this->assertStringContainsString(
            '<a href="/cedric-taldu/admin/oeuvres/' . $oeuvre . '">Œuvre sans titre (CT-VIDE) — image principale</a>',
            $this->requete('GET', self::MEDIAS . '/' . $media)->body,
        );
    }

    public function test_la_suppression_refusee_renvoie_a_la_liste_des_usages(): void
    {
        $media = (new MediaFactory($this->pdo))->named('encre')->create();
        (new PostFactory($this->pdo))->withCover($media)->translated('fr', 'vernissage', 'Vernissage')->create();

        $reponse = $this->postAvecJeton(self::MEDIAS . '/' . $media . '/suppression');

        $this->assertSame(409, $reponse->status);
        $this->assertStringContainsString('Cette image est utilisée : retirez-la d’abord des endroits listés sous « Utilisée par ».', $reponse->body);
        $this->assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM media WHERE id = ' . $media)->fetchColumn());
    }
}
