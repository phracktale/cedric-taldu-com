<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

/**
 * Le schema du catalogue porte des invariants que le code ne peut pas garantir
 * seul : unicite d'un slug par langue, refus de supprimer une rubrique qui
 * contient des œuvres, cascade sur les traductions.
 *
 * Les verifier ici, c'est verifier que la base tient meme si une future
 * fonctionnalite oublie de le faire.
 */
final class SchemaCatalogueTest extends DatabaseTestCase
{
    #[DataProvider('tablesDuCatalogue')]
    public function test_la_table_est_creee(string $table): void
    {
        $this->assertContains($table, $this->tables());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tablesDuCatalogue(): iterable
    {
        foreach ([
            'media',
            'media_translations',
            'categories',
            'category_translations',
            'series',
            'series_translations',
            'artworks',
            'artwork_translations',
            'artwork_media',
        ] as $table) {
            yield $table => [$table];
        }
    }

    // ------------------------------------------------------------- unicite

    public function test_un_slug_de_rubrique_est_unique_par_langue(): void
    {
        // Deux rubriques au meme slug rendraient /fr/galerie/encres ambigu.
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');

        $this->expectException(PDOException::class);

        $this->creerRubrique(2, 'fr', 'encres', 'Autres encres');
    }

    public function test_le_meme_slug_est_permis_dans_deux_langues_differentes(): void
    {
        // /fr/galerie/contact et /en/gallery/contact sont deux URL distinctes.
        $this->creerRubrique(1, 'fr', 'portraits', 'Portraits');
        $this->creerRubrique(2, 'en', 'portraits', 'Portraits');

        $this->assertSame(2, $this->compter('category_translations'));
    }

    public function test_une_reference_d_atelier_est_unique(): void
    {
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');
        $this->creerOeuvre(1, 1, 'CT-ENC-001');

        $this->expectException(PDOException::class);

        $this->creerOeuvre(2, 1, 'CT-ENC-001');
    }

    public function test_un_media_identique_ne_peut_pas_etre_stocke_deux_fois(): void
    {
        // media.checksum est unique : c'est la deduplication de 01-modele §2.
        $this->creerMedia(1, 'aaa');

        $this->expectException(PDOException::class);

        $this->creerMedia(2, 'aaa');
    }

    // -------------------------------------------------------- integrite

    public function test_une_rubrique_qui_contient_des_œuvres_ne_peut_pas_etre_supprimee(): void
    {
        // ON DELETE RESTRICT : supprimer une rubrique par erreur emporterait
        // tout le travail qu'elle contient.
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');
        $this->creerOeuvre(1, 1, 'CT-ENC-001');

        $this->expectException(PDOException::class);

        $this->pdo->exec('DELETE FROM categories WHERE id = 1');
    }

    public function test_supprimer_une_serie_delie_ses_œuvres_sans_les_perdre(): void
    {
        // ON DELETE SET NULL : la serie est un regroupement, pas un contenant.
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');
        $this->creerSerie(1, 1);
        $this->creerOeuvre(1, 1, 'CT-ENC-001', serieId: 1);

        $this->pdo->exec('DELETE FROM series WHERE id = 1');

        $ligne = $this->pdo->query('SELECT series_id FROM artworks WHERE id = 1')?->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($ligne);
        $this->assertNull($ligne['series_id']);
        $this->assertSame(1, $this->compter('artworks'));
    }

    public function test_supprimer_une_œuvre_emporte_ses_traductions(): void
    {
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');
        $this->creerOeuvre(1, 1, 'CT-ENC-001');
        $this->creerTraductionOeuvre(1, 'fr', 'articulation', 'Articulation');

        $this->pdo->exec('DELETE FROM artworks WHERE id = 1');

        $this->assertSame(0, $this->compter('artwork_translations'));
    }

    public function test_supprimer_un_media_delie_l_œuvre_sans_la_supprimer(): void
    {
        // ON DELETE SET NULL sur primary_media_id : perdre une image ne doit
        // pas faire disparaitre l'œuvre du catalogue.
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');
        $this->creerMedia(1, 'aaa');
        $this->creerOeuvre(1, 1, 'CT-ENC-001', mediaId: 1);

        $this->pdo->exec('DELETE FROM media WHERE id = 1');

        $this->assertSame(1, $this->compter('artworks'));
    }

    public function test_une_œuvre_ne_peut_pas_referencer_une_rubrique_inexistante(): void
    {
        $this->expectException(PDOException::class);

        $this->creerOeuvre(1, 999, 'CT-ENC-001');
    }

    public function test_le_meme_media_ne_peut_pas_etre_lie_deux_fois_a_la_meme_œuvre(): void
    {
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');
        $this->creerMedia(1, 'aaa');
        $this->creerOeuvre(1, 1, 'CT-ENC-001');

        $this->pdo->exec("INSERT INTO artwork_media (artwork_id, media_id, role, position) VALUES (1, 1, 'main', 0)");

        $this->expectException(PDOException::class);

        $this->pdo->exec("INSERT INTO artwork_media (artwork_id, media_id, role, position) VALUES (1, 1, 'detail', 1)");
    }

    // ------------------------------------------------------------ valeurs

    public function test_le_statut_par_defaut_d_une_œuvre_est_brouillon(): void
    {
        // Rien ne se publie par accident : une œuvre saisie n'est pas visible
        // tant que l'artiste ne l'a pas decidee.
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');
        $this->creerOeuvre(1, 1, 'CT-ENC-001');

        $ligne = $this->pdo->query('SELECT status, is_published FROM artworks WHERE id = 1')?->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($ligne);
        $this->assertSame('draft', $ligne['status']);
        $this->assertSame(0, $ligne['is_published']);
    }

    public function test_une_rubrique_n_est_pas_publiee_par_defaut(): void
    {
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');

        $ligne = $this->pdo->query('SELECT is_published FROM categories WHERE id = 1')?->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($ligne);
        $this->assertSame(0, $ligne['is_published']);
    }

    public function test_un_prix_ne_peut_pas_etre_negatif(): void
    {
        // price_cents est UNSIGNED : la base refuse ce que le domaine refuse.
        $this->creerRubrique(1, 'fr', 'encres', 'Encres');

        $this->expectException(PDOException::class);

        $this->pdo->exec(
            "INSERT INTO artworks (id, category_id, reference, price_cents, created_at, updated_at)
             VALUES (1, 1, 'CT-ENC-001', -1, NOW(), NOW())"
        );
    }

    // ------------------------------------------------------------ outils

    private function compter(string $table): int
    {
        // Nom de table litteral, jamais une entree utilisateur.
        $statement = $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function creerMedia(int $id, string $checksum): void
    {
        $this->pdo->prepare(
            'INSERT INTO media (id, storage_path, public_basename, mime, width, height, bytes, checksum, created_at)
             VALUES (:id, :path, :base, :mime, 2400, 3200, 1024, :checksum, NOW())'
        )->execute([
            'id' => $id,
            'path' => 'storage/uploads/ab/cd/' . $id . '.jpg',
            'base' => 'media-' . $id,
            'mime' => 'image/jpeg',
            'checksum' => str_pad($checksum, 64, '0'),
        ]);
    }

    private function creerRubrique(int $id, string $locale, string $slug, string $titre): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO categories (id, created_at, updated_at) VALUES (:id, NOW(), NOW())')
            ->execute(['id' => $id]);

        $this->pdo->prepare(
            'INSERT INTO category_translations (category_id, locale, slug, title) VALUES (:id, :locale, :slug, :title)'
        )->execute(['id' => $id, 'locale' => $locale, 'slug' => $slug, 'title' => $titre]);
    }

    private function creerSerie(int $id, int $categorieId): void
    {
        $this->pdo->prepare(
            'INSERT INTO series (id, category_id, created_at, updated_at) VALUES (:id, :cat, NOW(), NOW())'
        )->execute(['id' => $id, 'cat' => $categorieId]);
    }

    private function creerOeuvre(
        int $id,
        int $categorieId,
        string $reference,
        ?int $serieId = null,
        ?int $mediaId = null,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO artworks (id, category_id, series_id, reference, primary_media_id, created_at, updated_at)
             VALUES (:id, :cat, :serie, :ref, :media, NOW(), NOW())'
        )->execute([
            'id' => $id,
            'cat' => $categorieId,
            'serie' => $serieId,
            'ref' => $reference,
            'media' => $mediaId,
        ]);
    }

    private function creerTraductionOeuvre(int $oeuvreId, string $locale, string $slug, string $titre): void
    {
        $this->pdo->prepare(
            'INSERT INTO artwork_translations (artwork_id, locale, slug, title) VALUES (:id, :locale, :slug, :title)'
        )->execute(['id' => $oeuvreId, 'locale' => $locale, 'slug' => $slug, 'title' => $titre]);
    }
}
