<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\PostFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Enregistrement automatique (demande du 2026-10-29) : les formulaires de
 * SAISIE portent `data-autosave`, que public/assets/js/autosave.js arme — il
 * enregistre chaque changement et masque les boutons « Enregistrer ».
 *
 * Les formulaires d'ACTION n'en portent pas : un remboursement, un changement
 * de régime de TVA, un mot de passe restent des gestes volontaires.
 */
final class EnregistrementAutomatiqueTest extends AdminTestCase
{
    private const BASE = '/cedric-taldu';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    /**
     * @return iterable<string, array{string, string}> page, action du formulaire de saisie
     */
    public static function reglages(): iterable
    {
        yield 'global' => ['/admin/global', '/admin/global'];
        yield 'contact' => ['/admin/contact', '/admin/contact'];
        yield 'apparence' => ['/admin/apparence', '/admin/apparence'];
        yield 'impression' => ['/admin/impression', '/admin/impression'];
        yield 'livraison' => ['/admin/livraison', '/admin/livraison'];
        yield 'tarifs de livraison' => ['/admin/livraison', '/admin/livraison/tarifs'];
        yield 'carte' => ['/admin/carte', '/admin/carte'];
        yield 'templates' => ['/admin/templates', '/admin/templates'];
        yield 'menu' => ['/admin/menu', '/admin/menu'];
        yield 'accueil' => ['/admin/accueil', '/admin/accueil'];
        yield 'identité du vendeur' => ['/admin/facturation', '/admin/facturation'];
        yield 'page des galeries' => ['/admin/galeries/page', '/admin/galeries/page'];
        yield 'classement des œuvres' => ['/admin/oeuvres', '/admin/oeuvres/classement'];
        yield 'nouvelle œuvre' => ['/admin/oeuvres/nouvelle', '/admin/oeuvres'];
        yield 'nouvel article' => ['/admin/actus/nouvel-article', '/admin/actus'];
        yield 'nouvelle galerie' => ['/admin/galeries/nouvelle', '/admin/galeries'];
    }

    #[DataProvider('reglages')]
    public function test_les_formulaires_de_saisie_s_enregistrent_d_eux_memes(string $page, string $action): void
    {
        $this->assertTrue($this->autoEnregistre($page, $action));
    }

    public function test_une_creation_est_signalee_comme_telle(): void
    {
        // Le premier enregistrement crée l'élément : le module bascule alors
        // sur sa page d'édition.
        $html = $this->requete('GET', self::BASE . '/admin/oeuvres/nouvelle')->body;

        $this->assertMatchesRegularExpression(
            '~<form method="post" action="' . self::BASE . '/admin/oeuvres"[^>]*\sdata-autosave="creation"~',
            $html,
        );
    }

    public function test_la_fiche_d_une_oeuvre_s_enregistre_d_elle_meme(): void
    {
        $id = (new ArtworkFactory($this->pdo))->create((new CategoryFactory($this->pdo))->create());

        $this->assertTrue($this->autoEnregistre('/admin/oeuvres/' . $id, '/admin/oeuvres/' . $id));
    }

    public function test_un_article_s_enregistre_de_lui_meme(): void
    {
        $id = (new PostFactory($this->pdo))->create();

        $this->assertTrue($this->autoEnregistre('/admin/actus/' . $id, '/admin/actus/' . $id));
    }

    public function test_une_galerie_s_enregistre_d_elle_meme(): void
    {
        $id = (new CategoryFactory($this->pdo))->create();

        $this->assertTrue($this->autoEnregistre('/admin/galeries/' . $id, '/admin/galeries/' . $id));
    }

    public function test_une_page_s_enregistre_d_elle_meme(): void
    {
        $id = (int) $this->pdo->query('SELECT id FROM pages ORDER BY id LIMIT 1')->fetchColumn();

        $this->assertTrue($this->autoEnregistre('/admin/pages/' . $id, '/admin/pages/' . $id));
    }

    public function test_la_fiche_d_un_media_s_enregistre_d_elle_meme(): void
    {
        $id = (new MediaFactory($this->pdo))->create();

        $this->assertTrue($this->autoEnregistre('/admin/medias/' . $id, '/admin/medias/' . $id));
    }

    public function test_le_prix_d_un_format_de_tirage_s_enregistre_de_lui_meme(): void
    {
        $oeuvre = (new ArtworkFactory($this->pdo))->create((new CategoryFactory($this->pdo))->create());
        $this->pdo->prepare(
            "INSERT INTO products (artwork_id, kind, processing_mode, is_published, created_at, updated_at)
             VALUES (:art, 'standard', 'prodigi_auto', 0, NOW(), NOW())"
        )->execute(['art' => $oeuvre]);
        $this->pdo->prepare(
            "INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           created_at, updated_at)
             VALUES (:p, 'CT-TEST-1', '30 × 40 cm', 3000, 999, 300, NOW(), NOW())"
        )->execute(['p' => (int) $this->pdo->lastInsertId()]);
        $variante = (int) $this->pdo->lastInsertId();

        $this->assertTrue($this->autoEnregistre('/admin/oeuvres/' . $oeuvre . '/reproductions', '/admin/variantes/' . $variante));
    }

    public function test_le_regime_de_tva_reste_un_geste_volontaire(): void
    {
        $this->assertFalse($this->autoEnregistre('/admin/facturation', '/admin/facturation/tva'));
    }

    public function test_un_remboursement_reste_un_geste_volontaire(): void
    {
        $id = (new OrderFactory($this->pdo))->create();

        $this->assertFalse($this->autoEnregistre('/admin/commandes/' . $id . '/remboursement', '/admin/commandes/' . $id . '/remboursement'));
    }

    public function test_un_mot_de_passe_reste_un_geste_volontaire(): void
    {
        $this->assertFalse($this->autoEnregistre('/admin/compte/mot-de-passe', '/admin/compte/mot-de-passe'));
    }

    /**
     * Le formulaire posté vers $action, sur la page $page, porte-t-il data-autosave ?
     */
    private function autoEnregistre(string $page, string $action): bool
    {
        $reponse = $this->requete('GET', self::BASE . $page);
        $this->assertSame(200, $reponse->status, $page);

        $trouve = preg_match_all(
            '~<form method="post"\s+action="' . preg_quote(self::BASE . $action, '~') . '"([^>]*)>~',
            $reponse->body,
            $formulaires,
        );
        $this->assertGreaterThan(0, $trouve, 'aucun formulaire vers ' . $action . ' sur ' . $page);

        foreach ($formulaires[1] as $attributs) {
            if (preg_match('~\sdata-autosave(?:[\s=]|$)~', $attributs) === 1) {
                return true;
            }
        }

        return false;
    }
}
