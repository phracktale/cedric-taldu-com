<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\Cta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bouton d'appel à l'action paramétrable (revue du 2026-09-24).
 *
 * Remplace les boutons écrits en dur : l'artiste choisit le libellé (par
 * langue), la cible, le style et l'alignement.
 */
final class CtaTest extends TestCase
{
    public function test_un_cta_complet_est_relu_tel_quel(): void
    {
        $cta = Cta::fromStored(
            ['target' => 'booklet', 'style' => 'vide', 'align' => 'droite'],
            'Lire le livret',
        );

        $this->assertNotNull($cta);
        $this->assertSame('Lire le livret', $cta->label);
        $this->assertSame('booklet', $cta->target);
        $this->assertSame('vide', $cta->style);
        $this->assertSame('droite', $cta->align);
    }

    public function test_sans_libelle_il_n_y_a_pas_de_bouton(): void
    {
        $this->assertNull(Cta::fromStored(['target' => 'contact'], ''));
        $this->assertNull(Cta::fromStored(['target' => 'contact'], '   '));
        $this->assertNull(Cta::fromStored(['target' => 'contact'], null));
    }

    public function test_les_valeurs_par_defaut_sont_plein_centre_et_galeries(): void
    {
        $cta = Cta::fromStored([], 'Voir');

        $this->assertNotNull($cta);
        $this->assertSame('galleries', $cta->target);
        $this->assertSame('plein', $cta->style);
        $this->assertSame('centre', $cta->align);
    }

    public function test_des_valeurs_inconnues_retombent_sur_les_defauts(): void
    {
        $cta = Cta::fromStored(['target' => 'evil', 'style' => '"><x', 'align' => 'milieu'], 'Voir');

        $this->assertNotNull($cta);
        $this->assertSame('galleries', $cta->target);
        $this->assertSame('plein', $cta->style);
        $this->assertSame('centre', $cta->align);
    }

    public function test_une_rubrique_cible_porte_son_identifiant(): void
    {
        $cta = Cta::fromStored(['target' => 'category', 'category_id' => '12'], 'Encres');

        $this->assertNotNull($cta);
        $this->assertSame('category', $cta->target);
        $this->assertSame(12, $cta->categoryId);
    }

    public function test_une_rubrique_sans_identifiant_retombe_sur_les_galeries(): void
    {
        $cta = Cta::fromStored(['target' => 'category', 'category_id' => 'abc'], 'Encres');

        $this->assertNotNull($cta);
        $this->assertSame('galleries', $cta->target);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsAcceptees(): iterable
    {
        yield 'chemin interne' => ['/fr/livret'];
        yield 'https' => ['https://www.example.org/expo'];
        yield 'ancre interne' => ['/fr/#contact'];
    }

    #[DataProvider('urlsAcceptees')]
    public function test_une_url_libre_sure_est_conservee(string $url): void
    {
        $cta = Cta::fromStored(['target' => 'url', 'url' => $url], 'Voir');

        $this->assertNotNull($cta);
        $this->assertSame('url', $cta->target);
        $this->assertSame($url, $cta->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsRefusees(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'protocole relatif' => ['//evil.example/x'];
        yield 'data' => ['data:text/html,<script>'];
        yield 'http en clair' => ['http://example.org'];
        yield 'relatif sans barre' => ['fr/livret'];
        yield 'saut de ligne' => ["/fr/\nlivret"];
        yield 'vide' => [''];
    }

    #[DataProvider('urlsRefusees')]
    public function test_une_url_libre_dangereuse_retombe_sur_les_galeries(string $url): void
    {
        $cta = Cta::fromStored(['target' => 'url', 'url' => $url], 'Voir');

        $this->assertNotNull($cta);
        $this->assertSame('galleries', $cta->target);
        $this->assertNull($cta->url);
    }

    public function test_to_array_ne_garde_que_les_reglages_communs(): void
    {
        // Le libellé est traduit ; il vit dans la partie par langue du réglage.
        $cta = Cta::fromStored(['target' => 'category', 'category_id' => 4, 'style' => 'vide'], 'Encres');

        $this->assertNotNull($cta);
        $this->assertSame(
            ['target' => 'category', 'category_id' => 4, 'url' => null, 'style' => 'vide', 'align' => 'centre'],
            $cta->toArray(),
        );
    }
}
