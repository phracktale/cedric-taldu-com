<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shop;

use App\Domain\Exception\InvalidCartQuantity;
use App\Domain\Locale;
use App\Domain\Shop\Cart;
use App\Domain\Shop\LineKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Panier (03-boutique §2).
 *
 * AUCUN PRIX ne vit ici. Le panier ne retient qu'une identite et une quantite ;
 * les montants sont recalcules depuis le catalogue a chaque affichage. C'est ce
 * qui rend impossible qu'un panier porte un prix perime — ou un prix choisi par
 * le client.
 */
#[CoversClass(Cart::class)]
#[CoversClass(LineKind::class)]
final class CartTest extends TestCase
{
    public function test_un_panier_neuf_est_vide(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr);

        $this->assertTrue($panier->isEmpty());
        $this->assertSame([], $panier->lines);
        $this->assertSame(0, $panier->itemCount());
    }

    public function test_une_reproduction_s_ajoute_avec_sa_quantite(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, 3);

        $this->assertFalse($panier->isEmpty());
        $this->assertCount(1, $panier->lines);
        $this->assertSame(LineKind::Reproduction, $panier->lines[0]->kind);
        $this->assertSame(12, $panier->lines[0]->targetId);
        $this->assertSame(3, $panier->lines[0]->quantity);
    }

    public function test_une_ligne_dupliquee_incremente_la_quantite(): void
    {
        // Sans fusion, le panier afficherait deux lignes identiques et la
        // contrainte d'unicite de cart_items exploserait a l'ecriture.
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Reproduction, 12, 2)
            ->add(LineKind::Reproduction, 12, 1);

        $this->assertCount(1, $panier->lines);
        $this->assertSame(3, $panier->lines[0]->quantity);
    }

    public function test_deux_genres_de_ligne_sur_le_meme_identifiant_ne_fusionnent_pas(): void
    {
        // artwork_id 12 et variant_id 12 designent deux objets sans rapport :
        // la ligne est identifiee par le COUPLE (genre, identifiant).
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 12, 1)
            ->add(LineKind::Reproduction, 12, 1);

        $this->assertCount(2, $panier->lines);
    }

    public function test_la_quantite_d_une_reproduction_est_bornee_a_cinq(): void
    {
        // 03-boutique §2. Un POST forge a 99 est ramene a 5, pas rejete : le
        // client obtient un panier valide plutot qu'une erreur.
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, 99);

        $this->assertSame(5, $panier->lines[0]->quantity);
    }

    public function test_la_borne_s_applique_aussi_a_la_fusion(): void
    {
        // Ajouter trois fois trois exemplaires ne doit pas contourner la borne.
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Reproduction, 12, 3)
            ->add(LineKind::Reproduction, 12, 3)
            ->add(LineKind::Reproduction, 12, 3);

        $this->assertSame(5, $panier->lines[0]->quantity);
    }

    public function test_la_quantite_d_un_original_est_bornee_a_un(): void
    {
        // Une piece unique : 03-boutique §2 et l'invariant 01-modele §7.1.
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Original, 7, 4);

        $this->assertSame(1, $panier->lines[0]->quantity);
    }

    public function test_une_oeuvre_originale_ne_figure_qu_une_fois(): void
    {
        // Contrainte d'unicite en base (03-boutique §2) : deux ajouts du meme
        // original restent une seule ligne a la quantite 1.
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 7, 1)
            ->add(LineKind::Original, 7, 1);

        $this->assertCount(1, $panier->lines);
        $this->assertSame(1, $panier->lines[0]->quantity);
    }

    public function test_une_quantite_nulle_a_l_ajout_est_refusee(): void
    {
        // Ajouter zero exemplaire n'a pas de sens : c'est un formulaire mal
        // rempli ou une requete forgee, pas une intention d'achat.
        $this->expectException(InvalidCartQuantity::class);

        Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, 0);
    }

    public function test_une_quantite_negative_a_l_ajout_est_refusee(): void
    {
        $this->expectException(InvalidCartQuantity::class);

        Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, -1);
    }

    public function test_une_quantite_se_redefinit(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Reproduction, 12, 3)
            ->setQuantity(LineKind::Reproduction, 12, 1);

        $this->assertSame(1, $panier->lines[0]->quantity);
    }

    public function test_redefinir_la_quantite_a_zero_retire_la_ligne(): void
    {
        // C'est le comportement attendu du champ « quantite » du panier : le
        // mettre a zero est la facon naturelle de retirer un article.
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Reproduction, 12, 3)
            ->setQuantity(LineKind::Reproduction, 12, 0);

        $this->assertTrue($panier->isEmpty());
    }

    public function test_redefinir_la_quantite_respecte_la_borne(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Reproduction, 12, 1)
            ->setQuantity(LineKind::Reproduction, 12, 99);

        $this->assertSame(5, $panier->lines[0]->quantity);
    }

    public function test_redefinir_la_quantite_d_une_ligne_absente_ne_fait_rien(): void
    {
        // Un formulaire rejoue apres qu'un autre onglet a vide le panier ne doit
        // pas ressusciter la ligne.
        $panier = Cart::empty('jeton', Locale::Fr)->setQuantity(LineKind::Reproduction, 12, 2);

        $this->assertTrue($panier->isEmpty());
    }

    public function test_une_ligne_se_retire(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Reproduction, 12, 3)
            ->add(LineKind::Original, 7, 1)
            ->remove(LineKind::Reproduction, 12);

        $this->assertCount(1, $panier->lines);
        $this->assertSame(LineKind::Original, $panier->lines[0]->kind);
    }

    public function test_retirer_une_ligne_absente_ne_fait_rien(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 7, 1)
            ->remove(LineKind::Reproduction, 12);

        $this->assertCount(1, $panier->lines);
    }

    public function test_la_pastille_compte_les_articles_et_non_les_lignes(): void
    {
        // La pastille de l'en-tete annonce un nombre d'articles : compter les
        // lignes afficherait « 2 » pour un panier de six exemplaires.
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Reproduction, 12, 5)
            ->add(LineKind::Original, 7, 1);

        $this->assertSame(6, $panier->itemCount());
    }

    public function test_un_panier_est_immuable(): void
    {
        // ARCHITECTURE §4. Deux requetes concurrentes sur la meme session ne
        // doivent pas se marcher dessus par un objet partage.
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, 1);

        $panier->add(LineKind::Reproduction, 12, 1)->remove(LineKind::Reproduction, 12);

        $this->assertCount(1, $panier->lines);
        $this->assertSame(1, $panier->lines[0]->quantity);
    }

    public function test_l_ordre_d_ajout_des_lignes_est_conserve(): void
    {
        // Un panier dont les lignes sautent d'un affichage a l'autre donne
        // l'impression que le site a change quelque chose.
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Reproduction, 12, 1)
            ->add(LineKind::Original, 7, 1)
            ->add(LineKind::Reproduction, 30, 1);

        $this->assertSame(
            [[LineKind::Reproduction, 12], [LineKind::Original, 7], [LineKind::Reproduction, 30]],
            array_map(static fn ($l): array => [$l->kind, $l->targetId], $panier->lines),
        );
    }

    public function test_le_genre_de_ligne_correspond_aux_valeurs_de_la_base(): void
    {
        $this->assertSame(
            ['original', 'reproduction'],
            array_map(static fn (LineKind $k): string => $k->value, LineKind::cases()),
        );
    }

    public function test_chaque_genre_porte_sa_propre_borne(): void
    {
        $this->assertSame(1, LineKind::Original->maxQuantityPerLine());
        $this->assertSame(5, LineKind::Reproduction->maxQuantityPerLine());
    }
}
