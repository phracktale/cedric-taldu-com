<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Locale;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\Cart;
use App\Domain\Shop\LineKind;
use App\Repository\CartRepository;
use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;

/**
 * Persistance du panier et lecture du catalogue achetable.
 *
 * Deux responsabilites, volontairement dans le meme depot : le panier ne sert a
 * rien sans l'instantane de catalogue qui lui donne ses prix, et les charger
 * ensemble evite une requete par ligne sur la page la plus rechargee du tunnel.
 */
final class CartRepositoryTest extends DatabaseTestCase
{
    private const MAINTENANT = '2026-07-22 10:00:00';

    private CartRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new CartRepository($this->pdo);
    }

    // ----------------------------------------------------------- ouverture

    public function test_un_jeton_inconnu_ouvre_un_panier_neuf(): void
    {
        $panier = $this->repository->open(null, Locale::Fr);

        $this->assertTrue($panier->isEmpty());
        $this->assertSame(Locale::Fr, $panier->locale);
        $this->assertSame(1, $this->compter('carts'));
    }

    public function test_le_jeton_d_un_panier_neuf_fait_soixante_quatre_caracteres(): void
    {
        // 03-boutique §2 : « jeton aleatoire de 32 octets ». En hexadecimal,
        // cela fait 64 caracteres, et carts.token est un CHAR(64).
        $panier = $this->repository->open(null, Locale::Fr);

        $this->assertSame(64, strlen($panier->token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $panier->token);
    }

    public function test_deux_paniers_neufs_ont_des_jetons_differents(): void
    {
        $un = $this->repository->open(null, Locale::Fr);
        $deux = $this->repository->open(null, Locale::Fr);

        $this->assertNotSame($un->token, $deux->token);
    }

    public function test_un_jeton_forge_n_ouvre_pas_le_panier_d_un_autre(): void
    {
        // Le jeton est la SEULE chose qui protege un panier. Un jeton inconnu
        // doit donner un panier neuf, jamais une erreur qui confirmerait
        // l'existence des autres.
        $this->repository->open(null, Locale::Fr);

        $panier = $this->repository->open(str_repeat('f', 64), Locale::Fr);

        $this->assertTrue($panier->isEmpty());
        $this->assertNotSame(str_repeat('f', 64), $panier->token);
    }

    public function test_un_jeton_mal_forme_ne_touche_pas_la_base(): void
    {
        // Une valeur venue d'un cookie : elle ne doit jamais atteindre une
        // requete sous une forme inattendue.
        foreach (['', 'court', str_repeat('z', 64), "abc\0def", '../../etc/passwd'] as $jeton) {
            $panier = $this->repository->open($jeton, Locale::Fr);

            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $panier->token);
        }
    }

    public function test_un_panier_existant_est_retrouve_par_son_jeton(): void
    {
        $original = $this->repository->open(null, Locale::Fr);
        $artwork = $this->creerOeuvre();
        $this->repository->save($original->add(LineKind::Original, $artwork, 1));

        $retrouve = $this->repository->open($original->token, Locale::Fr);

        $this->assertSame($original->token, $retrouve->token);
        $this->assertCount(1, $retrouve->lines);
        $this->assertSame($artwork, $retrouve->lines[0]->targetId);
    }

    // ------------------------------------------------------- enregistrement

    public function test_les_lignes_ajoutees_sont_enregistrees(): void
    {
        $panier = $this->repository->open(null, Locale::Fr);
        $artwork = $this->creerOeuvre();
        $variante = $this->creerVariante();

        $this->repository->save(
            $panier->add(LineKind::Original, $artwork, 1)->add(LineKind::Reproduction, $variante, 3)
        );

        $this->assertSame(2, $this->compter('cart_items'));
        $this->assertSame(
            3,
            (int) $this->valeur("SELECT qty FROM cart_items WHERE kind = 'reproduction'"),
        );
    }

    public function test_une_quantite_modifiee_est_reecrite(): void
    {
        $panier = $this->repository->open(null, Locale::Fr);
        $variante = $this->creerVariante();

        $this->repository->save($panier->add(LineKind::Reproduction, $variante, 3));
        $this->repository->save(
            $this->repository->open($panier->token, Locale::Fr)
                ->setQuantity(LineKind::Reproduction, $variante, 1)
        );

        $this->assertSame(1, $this->compter('cart_items'));
        $this->assertSame(1, (int) $this->valeur('SELECT qty FROM cart_items'));
    }

    public function test_une_ligne_retiree_disparait_de_la_base(): void
    {
        $panier = $this->repository->open(null, Locale::Fr);
        $variante = $this->creerVariante();

        $this->repository->save($panier->add(LineKind::Reproduction, $variante, 3));
        $this->repository->save($this->repository->open($panier->token, Locale::Fr)->remove(LineKind::Reproduction, $variante));

        $this->assertSame(0, $this->compter('cart_items'));
        // Le panier lui-meme survit : le visiteur garde son jeton.
        $this->assertSame(1, $this->compter('carts'));
    }

    public function test_enregistrer_deux_fois_le_meme_panier_ne_duplique_rien(): void
    {
        // Un double envoi de formulaire, ou un rechargement : l'ecriture doit
        // etre idempotente.
        $panier = $this->repository->open(null, Locale::Fr);
        $variante = $this->creerVariante();
        $avecLigne = $panier->add(LineKind::Reproduction, $variante, 2);

        $this->repository->save($avecLigne);
        $this->repository->save($avecLigne);

        $this->assertSame(1, $this->compter('cart_items'));
        $this->assertSame(2, (int) $this->valeur('SELECT qty FROM cart_items'));
    }

    public function test_enregistrer_touche_la_date_de_mise_a_jour(): void
    {
        // carts.updated_at porte la purge a 60 jours : un panier actif ne doit
        // pas etre efface sous les pieds de son visiteur.
        $panier = $this->repository->open(null, Locale::Fr);
        $this->pdo->exec("UPDATE carts SET updated_at = '2020-01-01 00:00:00'");

        $this->repository->save($panier->add(LineKind::Reproduction, $this->creerVariante(), 1));

        $this->assertNotSame('2020-01-01 00:00:00', $this->valeur('SELECT updated_at FROM carts'));
    }

    // -------------------------------------------------------------- purge

    public function test_les_paniers_inactifs_sont_purges(): void
    {
        // 03-boutique §2 : purge au-dela de 60 jours. 06-securite §9 en fait
        // une obligation de conservation, pas une commodite.
        $vieux = $this->repository->open(null, Locale::Fr);
        $recent = $this->repository->open(null, Locale::Fr);

        $this->pdo->exec(
            "UPDATE carts SET updated_at = '2026-05-01 00:00:00' WHERE token = '{$vieux->token}'"
        );

        $supprimes = $this->repository->purgeInactiveSince(new DateTimeImmutable('2026-05-23 10:00:00'));

        // On verifie le SORT des deux paniers de ce test, pas le total de la
        // table : un COUNT global casserait des qu'un autre test laisse un
        // panier commite, alors que la purge, elle, s'est bien comportee.
        $this->assertGreaterThanOrEqual(1, $supprimes);
        $this->assertNull($this->valeur("SELECT id FROM carts WHERE token = '{$vieux->token}'"));
        $this->assertNotNull($this->valeur("SELECT id FROM carts WHERE token = '{$recent->token}'"));
    }

    public function test_la_purge_emporte_les_lignes_du_panier(): void
    {
        $panier = $this->repository->open(null, Locale::Fr);
        $this->repository->save($panier->add(LineKind::Reproduction, $this->creerVariante(), 1));
        $this->pdo->exec("UPDATE carts SET updated_at = '2026-05-01 00:00:00'");

        $this->repository->purgeInactiveSince(new DateTimeImmutable('2026-05-23 10:00:00'));

        $this->assertSame(0, $this->compter('cart_items'));
    }

    // -------------------------------------------- instantane du catalogue

    public function test_le_catalogue_rend_le_prix_et_la_categorie_d_un_original(): void
    {
        $artwork = $this->creerOeuvre(prix: 45000, titre: 'Articulation');
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Original, $artwork, 1);

        $item = $this->catalogue($panier)->find(LineKind::Original, $artwork);

        $this->assertNotNull($item);
        $this->assertSame(45000, $item->unitPrice->cents);
        $this->assertSame(VatCategory::OriginalArtwork, $item->vatCategory);
        $this->assertStringContainsString('Articulation', $item->label);
        $this->assertTrue($item->isSellable);
        $this->assertNull($item->stockQty);
    }

    public function test_une_oeuvre_vendue_n_est_pas_achetable(): void
    {
        $artwork = $this->creerOeuvre(statut: 'sold');
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Original, $artwork, 1);

        $item = $this->catalogue($panier)->find(LineKind::Original, $artwork);

        $this->assertNotNull($item);
        $this->assertFalse($item->isSellable);
    }

    public function test_une_oeuvre_non_publiee_n_est_pas_achetable(): void
    {
        $artwork = $this->creerOeuvre(publiee: false);
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Original, $artwork, 1);

        $item = $this->catalogue($panier)->find(LineKind::Original, $artwork);

        $this->assertNotNull($item);
        $this->assertFalse($item->isSellable);
    }

    public function test_une_oeuvre_sans_prix_n_est_pas_achetable(): void
    {
        // artworks.price_cents est NULL quand l'œuvre n'est pas vendable
        // (01-modele §3). Sans ce controle, elle partirait a zero euro.
        $artwork = $this->creerOeuvre(prix: null);
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Original, $artwork, 1);

        $item = $this->catalogue($panier)->find(LineKind::Original, $artwork);

        $this->assertNotNull($item);
        $this->assertFalse($item->isSellable);
    }

    public function test_une_reservation_echue_rend_l_oeuvre_a_nouveau_achetable(): void
    {
        // 01-modele §7.3, appliquee A LA LECTURE : c'est la regle du domaine
        // ArtworkStatus::effectiveAt() qui est relue ici, pas une seconde
        // version ecrite en SQL.
        $artwork = $this->creerOeuvre(statut: 'reserved', reserveJusquA: '2026-07-22 09:00:00');
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Original, $artwork, 1);

        $item = $this->catalogue($panier)->find(LineKind::Original, $artwork);

        $this->assertNotNull($item);
        $this->assertTrue($item->isSellable);
    }

    public function test_une_reservation_en_cours_rend_l_oeuvre_indisponible(): void
    {
        $artwork = $this->creerOeuvre(statut: 'reserved', reserveJusquA: '2026-07-22 11:00:00');
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Original, $artwork, 1);

        $item = $this->catalogue($panier)->find(LineKind::Original, $artwork);

        $this->assertNotNull($item);
        $this->assertFalse($item->isSellable);
    }

    public function test_le_catalogue_rend_le_stock_et_le_sku_d_une_variante(): void
    {
        $variante = $this->creerVariante(stock: 7, sku: 'ART-3040');
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Reproduction, $variante, 1);

        $item = $this->catalogue($panier)->find(LineKind::Reproduction, $variante);

        $this->assertNotNull($item);
        $this->assertSame(6000, $item->unitPrice->cents);
        $this->assertSame(7, $item->stockQty);
        $this->assertSame('ART-3040', $item->sku);
        $this->assertSame(VatCategory::StandardGoods, $item->vatCategory);
        $this->assertSame(300, $item->weightGrams);
    }

    public function test_le_libelle_d_une_variante_reprend_le_titre_et_la_taille(): void
    {
        // Ce libelle sera FIGE dans order_items : il doit suffire a identifier
        // ce qui a ete vendu, des annees plus tard, meme si le catalogue a
        // change.
        $variante = $this->creerVariante(titreProduit: 'Tirage d’art limité');

        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Reproduction, $variante, 1);
        $item = $this->catalogue($panier)->find(LineKind::Reproduction, $variante);

        $this->assertNotNull($item);
        $this->assertStringContainsString('Tirage d’art limité', $item->label);
        $this->assertStringContainsString('30 × 40 cm', $item->label);
    }

    public function test_une_variante_desactivee_n_est_pas_achetable(): void
    {
        $variante = $this->creerVariante(active: false);
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Reproduction, $variante, 1);

        $item = $this->catalogue($panier)->find(LineKind::Reproduction, $variante);

        $this->assertNotNull($item);
        $this->assertFalse($item->isSellable);
    }

    public function test_une_variante_d_un_produit_non_publie_n_est_pas_achetable(): void
    {
        $variante = $this->creerVariante(produitPublie: false);
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Reproduction, $variante, 1);

        $item = $this->catalogue($panier)->find(LineKind::Reproduction, $variante);

        $this->assertNotNull($item);
        $this->assertFalse($item->isSellable);
    }

    public function test_une_variante_d_une_oeuvre_non_publiee_n_est_pas_achetable(): void
    {
        // Vendre la reproduction d'une œuvre que le public ne voit pas
        // reviendrait a publier l'œuvre par la bande.
        $variante = $this->creerVariante(oeuvrePubliee: false);
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Reproduction, $variante, 1);

        $item = $this->catalogue($panier)->find(LineKind::Reproduction, $variante);

        $this->assertNotNull($item);
        $this->assertFalse($item->isSellable);
    }

    public function test_le_reste_d_une_edition_limitee_est_rendu(): void
    {
        $variante = $this->creerVariante(editionSize: 30, dejaVendus: 28);
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Reproduction, $variante, 1);

        $item = $this->catalogue($panier)->find(LineKind::Reproduction, $variante);

        $this->assertNotNull($item);
        $this->assertSame(2, $item->editionsRemaining);
    }

    public function test_une_edition_non_limitee_n_a_pas_de_reste(): void
    {
        $variante = $this->creerVariante();
        $panier = $this->repository->open(null, Locale::Fr)->add(LineKind::Reproduction, $variante, 1);

        $item = $this->catalogue($panier)->find(LineKind::Reproduction, $variante);

        $this->assertNotNull($item);
        $this->assertNull($item->editionsRemaining);
    }

    public function test_une_ligne_disparue_du_catalogue_est_absente_de_l_instantane(): void
    {
        $panier = new Cart('jeton', Locale::Fr, []);
        $panier = $panier->add(LineKind::Reproduction, 999999, 1);

        $this->assertNull($this->catalogue($panier)->find(LineKind::Reproduction, 999999));
    }

    public function test_un_panier_vide_ne_declenche_aucune_requete_de_catalogue(): void
    {
        $catalogue = $this->catalogue(Cart::empty('jeton', Locale::Fr));

        $this->assertNull($catalogue->find(LineKind::Original, 1));
    }

    // ------------------------------------------------------------ assistance

    private function catalogue(Cart $panier): \App\Domain\Shop\ItemCatalogue
    {
        return $this->repository->catalogueFor($panier, new DateTimeImmutable(self::MAINTENANT));
    }

    private function creerOeuvre(
        string $statut = 'available',
        ?int $prix = 45000,
        bool $publiee = true,
        ?string $reserveJusquA = null,
        string $titre = 'Articulation',
    ): int {
        $this->pdo->exec(
            'INSERT INTO categories (position, is_published, created_at, updated_at) VALUES (0, 1, NOW(), NOW())'
        );
        $category = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO artworks
                (category_id, reference, status, reserved_until, price_cents, weight_grams,
                 is_published, created_at, updated_at)
             VALUES (:cat, :ref, :status, :until, :price, 800, :published, NOW(), NOW())'
        );
        $statement->execute([
            'cat' => $category,
            'ref' => 'REF-' . bin2hex(random_bytes(6)),
            'status' => $statut,
            'until' => $reserveJusquA,
            'price' => $prix,
            'published' => $publiee ? 1 : 0,
        ]);

        $artwork = (int) $this->pdo->lastInsertId();

        $translation = $this->pdo->prepare(
            'INSERT INTO artwork_translations (artwork_id, locale, slug, title)
             VALUES (:id, :locale, :slug, :title)'
        );
        $translation->execute([
            'id' => $artwork,
            'locale' => 'fr',
            'slug' => 'oeuvre-' . $artwork,
            'title' => $titre,
        ]);

        return $artwork;
    }

    private function creerVariante(
        int $stock = 10,
        string $sku = null,
        bool $active = true,
        bool $produitPublie = true,
        bool $oeuvrePubliee = true,
        ?int $editionSize = null,
        int $dejaVendus = 0,
        string $titreProduit = 'Tirage d’art',
    ): int {
        $artwork = $this->creerOeuvre(publiee: $oeuvrePubliee);
        $kind = $editionSize === null ? 'standard' : 'limited';

        $product = $this->pdo->prepare(
            'INSERT INTO products
                (artwork_id, kind, edition_size, editions_sold, is_published, created_at, updated_at)
             VALUES (:art, :kind, :size, :sold, :published, NOW(), NOW())'
        );
        $product->execute([
            'art' => $artwork,
            'kind' => $kind,
            'size' => $editionSize,
            'sold' => $dejaVendus,
            'published' => $produitPublie ? 1 : 0,
        ]);
        $productId = (int) $this->pdo->lastInsertId();

        $title = $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :locale, :title)'
        );
        $title->execute(['id' => $productId, 'locale' => 'fr', 'title' => $titreProduit]);

        $variant = $this->pdo->prepare(
            'INSERT INTO product_variants
                (product_id, sku, size_label, price_cents, stock_qty, weight_grams, is_active,
                 created_at, updated_at)
             VALUES (:prod, :sku, :size, 6000, :stock, 300, :active, NOW(), NOW())'
        );
        $variant->execute([
            'prod' => $productId,
            'sku' => $sku ?? 'SKU-' . bin2hex(random_bytes(6)),
            'size' => '30 × 40 cm',
            'stock' => $stock,
            'active' => $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function compter(string $table): int
    {
        return (int) $this->valeur("SELECT COUNT(*) FROM `{$table}`");
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return null;
        }

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
