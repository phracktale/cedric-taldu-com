<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Redirection 301 au changement de slug (05-i18n-seo §5).
 *
 * Quand l'artiste renomme le slug d'une rubrique PUBLIÉE, l'ancienne URL — qui
 * peut être indexée ou partagée — redirige en permanence vers la nouvelle.
 */
final class RedirectionSlugTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_renommer_un_slug_publie_pose_une_301_depuis_l_ancien(): void
    {
        $id = (new CategoryFactory($this->pdo))->published()
            ->translated('fr', 'encres', 'Encres')->create();

        // L'ancienne URL répond d'abord normalement.
        $this->assertSame(200, $this->get('/cedric-taldu/fr/galerie/encres')->status);

        // L'artiste renomme le slug.
        $this->postAvecJeton('/cedric-taldu/admin/rubriques/' . $id, [
            'titre_fr' => 'Encres',
            'slug_fr' => 'encres-de-chine',
        ]);

        // La nouvelle URL répond.
        $this->assertSame(200, $this->get('/cedric-taldu/fr/galerie/encres-de-chine')->status);

        // L'ancienne redirige en 301 vers la nouvelle.
        $reponse = $this->get('/cedric-taldu/fr/galerie/encres');
        $this->assertSame(301, $reponse->status);
        $this->assertSame('/cedric-taldu/fr/galerie/encres-de-chine', $reponse->header('Location'));
    }

    public function test_une_url_inconnue_sans_redirection_reste_en_404(): void
    {
        $this->assertSame(404, $this->get('/cedric-taldu/fr/galerie/jamais-existe')->status);
    }
}
