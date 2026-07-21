<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidSlug;
use App\Domain\Slug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Slug::class)]
final class SlugTest extends TestCase
{
    // ----------------------------------------------------- depuis un titre

    #[DataProvider('titresEtSlugs')]
    public function test_un_titre_devient_un_slug(string $titre, string $attendu): void
    {
        $this->assertSame($attendu, Slug::fromTitle($titre)->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titresEtSlugs(): iterable
    {
        yield 'simple' => ['Articulation', 'articulation'];
        yield 'espaces' => ['Pilier I', 'pilier-i'];
        yield 'accents' => ['Œuvre récente à l’encre', 'oeuvre-recente-a-l-encre'];
        yield 'ligature œ' => ['Cœur', 'coeur'];
        yield 'ligature æ' => ['Nævus', 'naevus'];
        yield 'cédille' => ['Façade', 'facade'];
        yield 'tréma' => ['Maïs et Noël', 'mais-et-noel'];
        yield 'apostrophe droite' => ["L'atelier", 'l-atelier'];
        yield 'apostrophe typographique' => ['L’atelier', 'l-atelier'];
        yield 'ponctuation' => ['Corps visible, corps divisible !', 'corps-visible-corps-divisible'];
        yield 'tirets multiples' => ['Corps  —  vécu', 'corps-vecu'];
        yield 'tirets en bordure' => ['— Autoportrait —', 'autoportrait'];
        yield 'chiffres' => ['Étude n° 12', 'etude-n-12'];
        yield 'esperluette' => ['Encre & papier', 'encre-papier'];
        yield 'déjà un slug' => ['autoportrait-au-baron-samedi', 'autoportrait-au-baron-samedi'];
        yield 'majuscules accentuées' => ['ÉTUDE', 'etude'];
    }

    public function test_un_slug_produit_respecte_toujours_le_format_des_routes(): void
    {
        // Le format doit correspondre a Route::SLUG, sinon UrlGenerator refusera
        // de produire l'URL de l'œuvre.
        foreach (self::titresEtSlugs() as [$titre, $attendu]) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Slug::fromTitle($titre)->value,
                'Titre : ' . $titre
            );
        }
    }

    // ------------------------------------------------------------- refus

    #[DataProvider('titresSansSlugPossible')]
    public function test_un_titre_dont_rien_ne_subsiste_est_refuse(string $titre): void
    {
        // Plutot que de produire un slug vide ou invente, on refuse : le
        // back-office demandera une saisie manuelle.
        $this->expectException(InvalidSlug::class);

        Slug::fromTitle($titre);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function titresSansSlugPossible(): iterable
    {
        yield 'chaîne vide' => [''];
        yield 'espaces seuls' => ['   '];
        yield 'ponctuation seule' => ['!?…—'];
        yield 'caractères chinois' => ['山水画'];
        yield 'emoji' => ['🎨'];
    }

    // ------------------------------------------------- depuis une chaîne

    public function test_un_slug_valide_est_accepte_tel_quel(): void
    {
        $this->assertSame('pilier-i', Slug::fromString('pilier-i')->value);
    }

    #[DataProvider('slugsInvalides')]
    public function test_un_slug_malforme_est_refuse(string $valeur): void
    {
        $this->expectException(InvalidSlug::class);

        Slug::fromString($valeur);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function slugsInvalides(): iterable
    {
        yield 'majuscule' => ['Articulation'];
        yield 'accent' => ['oeuvre-récente'];
        yield 'tiret double' => ['a--b'];
        yield 'tiret initial' => ['-a'];
        yield 'tiret final' => ['a-'];
        yield 'espace' => ['a b'];
        yield 'slash' => ['a/b'];
        yield 'charge XSS' => ['<script>'];
        yield 'vide' => [''];
    }

    // -------------------------------------------------------- collision

    public function test_un_suffixe_leve_une_collision(): void
    {
        // Deux œuvres peuvent porter le meme titre : le depot detecte la
        // collision et demande le slug suivant.
        $slug = Slug::fromTitle('Articulation');

        $this->assertSame('articulation-2', $slug->withSuffix(2)->value);
        $this->assertSame('articulation-3', $slug->withSuffix(3)->value);
    }

    public function test_un_suffixe_ne_s_empile_pas(): void
    {
        $slug = Slug::fromTitle('Articulation');

        $this->assertSame('articulation-3', $slug->withSuffix(2)->withSuffix(3)->value);
    }

    public function test_un_suffixe_doit_etre_superieur_a_un(): void
    {
        $this->expectException(InvalidSlug::class);

        Slug::fromTitle('Articulation')->withSuffix(1);
    }

    // ------------------------------------------------------------ egalite

    public function test_deux_slugs_de_meme_valeur_sont_egaux(): void
    {
        $this->assertTrue(Slug::fromString('pilier-i')->equals(Slug::fromTitle('Pilier I')));
        $this->assertFalse(Slug::fromString('pilier-i')->equals(Slug::fromString('pilier-ii')));
    }

    public function test_un_slug_se_rend_sous_forme_de_chaine(): void
    {
        $this->assertSame('pilier-i', (string) Slug::fromString('pilier-i'));
    }

    // -------------------------------------------------------- longueur

    public function test_un_slug_trop_long_est_tronque_sur_un_tiret(): void
    {
        // artwork_translations.slug est un VARCHAR(190) : on tronque proprement
        // plutot que de laisser MySQL couper au milieu d'un mot.
        $titre = str_repeat('encre de chine sur papier ', 20);

        $slug = Slug::fromTitle($titre)->value;

        $this->assertLessThanOrEqual(190, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug);
    }
}
