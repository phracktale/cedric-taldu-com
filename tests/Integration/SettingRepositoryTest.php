<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Locale;
use App\Repository\SettingRepository;
use Tests\Support\DatabaseTestCase;

final class SettingRepositoryTest extends DatabaseTestCase
{
    private SettingRepository $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = new SettingRepository($this->pdo);
    }

    private function poser(string $cle, string $json): void
    {
        $this->pdo->prepare('INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, NOW())')
            ->execute(['k' => $cle, 'v' => $json]);
    }

    public function test_un_reglage_est_relu_comme_tableau(): void
    {
        $this->poser('home.hero', '{"fr":{"titre":"Bonjour"}}');

        $this->assertSame(['fr' => ['titre' => 'Bonjour']], $this->depot->json('home.hero'));
    }

    public function test_un_reglage_absent_rend_un_tableau_vide(): void
    {
        // Le site doit s'afficher avec une base de reglages vide : c'est l'etat
        // d'une installation neuve, et une page blanche y serait un defaut.
        $this->assertSame([], $this->depot->json('inexistant'));
    }

    public function test_le_contenu_est_choisi_selon_la_langue(): void
    {
        $this->poser('home.hero', '{"fr":{"titre":"Bonjour"},"en":{"titre":"Hello"}}');

        $this->assertSame('Hello', $this->depot->forLocale('home.hero', Locale::En)['titre'] ?? null);
    }

    public function test_le_contenu_replie_sur_le_francais(): void
    {
        // Meme regle que pour le catalogue : le francais est obligatoire,
        // l'anglais facultatif (05-i18n-seo §3).
        $this->poser('home.hero', '{"fr":{"titre":"Bonjour"}}');

        $this->assertSame('Bonjour', $this->depot->forLocale('home.hero', Locale::En)['titre'] ?? null);
    }

    public function test_la_base_refuse_elle_meme_un_json_invalide(): void
    {
        // settings.value est une colonne JSON : MySQL valide a l'ecriture, donc
        // un reglage corrompu ne peut pas exister. La garantie est plus forte
        // qu'une defense applicative — elle vaut aussi pour une saisie faite
        // directement en base.
        //
        // Le decodage du depot reste malgre tout tolerant : 01-modele-de-donnees
        // annonce MariaDB 10.6+ comme moteur possible, ou la validation d'une
        // colonne JSON n'est pas garantie de la meme facon.
        $this->expectException(\PDOException::class);

        $this->poser('home.hero', '{ceci n est pas du JSON');
    }

    public function test_un_document_json_qui_n_est_pas_un_objet_rend_un_tableau_vide(): void
    {
        // « 42 » est un JSON parfaitement valide, que MySQL accepte, et dont le
        // gabarit ne saurait rien faire.
        $this->poser('home.hero', '42');

        $this->assertSame([], $this->depot->json('home.hero'));
        $this->assertSame([], $this->depot->forLocale('home.hero', Locale::Fr));
    }

    public function test_plusieurs_reglages_se_chargent_en_une_fois(): void
    {
        // L'accueil lit huit reglages : les demander un par un serait huit
        // allers-retours sur la page la plus consultee du site.
        $this->poser('home.hero', '{"fr":{"titre":"Bonjour"}}');
        $this->poser('home.shop', '{"fr":{"titre":"Boutique"}}');

        $reglages = $this->depot->manyForLocale(['home.hero', 'home.shop', 'absent'], Locale::Fr);

        $this->assertSame('Bonjour', $reglages['home.hero']['titre'] ?? null);
        $this->assertSame('Boutique', $reglages['home.shop']['titre'] ?? null);
        $this->assertSame([], $reglages['absent']);
    }
}
