<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\ArtworkStatus;
use App\Domain\Catalog\ArtworkTranslation;
use App\Domain\Catalog\Dimensions;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Slug;
use App\Domain\Translations;
use PDO;
use PDOStatement;

/**
 * Acces aux œuvres.
 *
 * Une œuvre est PUBLIQUEMENT VISIBLE quand elle est publiee et que son statut
 * n'est pas « brouillon ». Une œuvre vendue reste visible : le site est le
 * portfolio de l'artiste autant que sa boutique, et retirer les pieces vendues
 * viderait la galerie a mesure qu'elle marche.
 *
 * Toutes les valeurs variables sont des parametres lies. Les seules
 * interpolations sont des LISTES DE MARQUEURS engendrees a partir d'un nombre
 * d'elements — jamais une donnee.
 */
final class ArtworkRepository
{
    private const SELECT = <<<'SQL'
        SELECT a.id, a.category_id, a.series_id, a.reference, a.year, a.technique,
               a.width_mm, a.height_mm, a.is_signed, a.price_cents, a.status,
               a.weight_grams, a.primary_media_id, a.position,
               t.locale, t.slug, t.eyebrow, t.title, t.description, t.detail,
               t.meta_title, t.meta_description
        FROM artworks a
        INNER JOIN artwork_translations t ON t.artwork_id = a.id
        SQL;

    /** Un brouillon n'atteint jamais le public, publie ou non. */
    private const VISIBLE = "a.is_published = 1 AND a.status <> 'draft'";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Œuvres d'une rubrique, filtrees par serie et paginees.
     *
     * @return list<Artwork>
     */
    public function findPublishedInCategory(int $categoryId, ?int $seriesId, int $limit, int $offset): array
    {
        $limit = max(0, $limit);
        $offset = max(0, $offset);

        if ($limit === 0) {
            return [];
        }

        // Deux temps : d'abord les identifiants ordonnes et pagines, puis leur
        // chargement complet. MySQL n'accepte pas de LIMIT dans une sous-requete
        // IN, et surtout la pagination porte sur les ŒUVRES et non sur les
        // lignes de traduction — limiter la jointure couperait une œuvre en deux.
        $sql = 'SELECT a.id FROM artworks a
                WHERE a.category_id = :category AND ' . self::VISIBLE
            . ($seriesId === null ? '' : ' AND a.series_id = :series')
            . ' ORDER BY a.position ASC, a.id ASC LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('category', $categoryId, PDO::PARAM_INT);

        if ($seriesId !== null) {
            $statement->bindValue('series', $seriesId, PDO::PARAM_INT);
        }

        // LIMIT et OFFSET DOIVENT etre lies en entier : sans emulation des
        // preparations, PDO les enverrait entre guillemets et MySQL refuserait
        // la requete.
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $this->findByIds(self::identifiers($statement));
    }

    /**
     * Toutes les œuvres visibles, tous rubriques confondues — pour le sitemap.
     *
     * @return list<Artwork>
     */
    public function findAllPublished(): array
    {
        $statement = $this->pdo->query(
            'SELECT a.id FROM artworks a WHERE ' . self::VISIBLE . ' ORDER BY a.id ASC'
        );

        return $statement === false ? [] : $this->findByIds(self::identifiers($statement));
    }

    public function countPublishedInCategory(int $categoryId, ?int $seriesId): int
    {
        $sql = 'SELECT COUNT(*) FROM artworks a WHERE a.category_id = :category AND ' . self::VISIBLE
            . ($seriesId === null ? '' : ' AND a.series_id = :series');

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('category', $categoryId, PDO::PARAM_INT);

        if ($seriesId !== null) {
            $statement->bindValue('series', $seriesId, PDO::PARAM_INT);
        }

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function findById(int $id): ?Artwork
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE a.id = :id AND ' . self::VISIBLE);
        $statement->execute(['id' => $id]);

        return $this->hydrateAll($statement->fetchAll())[0] ?? null;
    }

    /**
     * Œuvre visible portant ce slug DANS CETTE LANGUE.
     *
     * Meme regle que pour les rubriques : le slug francais repond sous /en/
     * tant qu'aucune traduction anglaise n'existe, et cesse d'y repondre des
     * qu'elle existe — sans quoi la meme fiche aurait deux URL anglaises.
     */
    public function findBySlug(Locale $locale, Slug $slug): ?Artwork
    {
        return $this->lookupBySlug($locale, $slug, self::VISIBLE);
    }

    /**
     * Œuvre portant ce slug, PUBLIEE OU NON.
     *
     * RESERVEE A L'APERCU SIGNE (04-back-office §5). Le seul appelant legitime
     * est le controleur de fiche, et seulement apres avoir verifie un jeton
     * PreviewToken valable POUR CETTE ŒUVRE. Toute autre utilisation ferait
     * fuiter les brouillons, ce que 06-securite §8 interdit explicitement — un
     * brouillon repond 404, jamais 403, pour ne pas confirmer son existence.
     *
     * La methode porte ce nom long precisement pour qu'un appel distrait se
     * remarque a la relecture.
     */
    public function findBySlugIncludingDrafts(Locale $locale, Slug $slug): ?Artwork
    {
        return $this->lookupBySlug($locale, $slug, '1 = 1');
    }

    /**
     * @param string $visibility condition litterale, jamais une donnee externe
     */
    private function lookupBySlug(Locale $locale, Slug $slug, string $visibility): ?Artwork
    {
        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE ' . $visibility . <<<'SQL'
               AND a.id = (
                   SELECT s.artwork_id
                   FROM artwork_translations s
                   WHERE s.slug = :slug AND s.locale IN (:locale, :reference)
                   ORDER BY s.locale = :ordering DESC
                   LIMIT 1
               )
            SQL
        );

        $statement->execute([
            'slug' => $slug->value,
            'locale' => $locale->value,
            'reference' => Locale::reference()->value,
            'ordering' => $locale->value,
        ]);

        $artwork = $this->hydrateAll($statement->fetchAll())[0] ?? null;

        if ($artwork === null) {
            return null;
        }

        return $artwork->slug($locale)->equals($slug) ? $artwork : null;
    }

    /**
     * « De la même recherche » : meme serie d'abord, puis meme rubrique, puis
     * les plus recentes. Jamais l'œuvre courante (02-front-public §4).
     *
     * @return list<Artwork>
     */
    public function findRelated(Artwork $artwork, int $limit): array
    {
        $limit = max(0, $limit);

        if ($limit === 0) {
            return [];
        }

        // Le rang de parente est calcule en SQL, mais l'ordre final est celui
        // des identifiants rendus : findByIds respecte l'ordre demande.
        $sql = 'SELECT a.id FROM artworks a
                WHERE ' . self::VISIBLE . ' AND a.id <> :courante
                ORDER BY
                    CASE
                        -- Pas de test IS NOT NULL : « a.series_id = NULL » n est
                        -- jamais vrai en SQL, donc une œuvre sans serie tombe
                        -- naturellement au rang suivant. Cela evite surtout de
                        -- repeter :serie, qu une preparation NON emulee ne sait
                        -- pas lier deux fois.
                        WHEN a.series_id = :serie THEN 2
                        WHEN a.category_id = :rubrique THEN 1
                        ELSE 0
                    END DESC,
                    a.published_at DESC, a.id DESC
                LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('courante', $artwork->id, PDO::PARAM_INT);
        $statement->bindValue(
            'serie',
            $artwork->seriesId,
            $artwork->seriesId === null ? PDO::PARAM_NULL : PDO::PARAM_INT,
        );
        $statement->bindValue('rubrique', $artwork->categoryId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $this->findByIds(self::identifiers($statement));
    }

    /**
     * @return list<int>
     */
    private static function identifiers(PDOStatement $statement): array
    {
        /** @var list<int|string> $ids */
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_map(intval(...), $ids);
    }

    /**
     * Œuvres designees, RENDUES DANS L'ORDRE DEMANDE.
     *
     * Sert la vitrine de l'accueil, ou l'ordre est signifiant : la piece du
     * milieu est presentee plus haute que les deux autres.
     *
     * @param list<int> $ids
     * @return list<Artwork>
     */
    public function findByIds(array $ids): array
    {
        // Une clause IN () vide est une erreur de syntaxe SQL : le cas se traite
        // avant la requete, pas par la base.
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];

        foreach (array_values($ids) as $index => $id) {
            $name = 'id' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        // La seule chose interpolee est la liste de MARQUEURS engendree
        // ci-dessus, dont ni le nombre ni la forme ne viennent d'une donnee.
        // Les valeurs, elles, sont liees.
        $sql = sprintf(
            self::SELECT . ' WHERE a.id IN (%s) AND ' . self::VISIBLE,
            implode(', ', $placeholders),
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        $artworks = $this->hydrateAll($statement->fetchAll());

        return self::inRequestedOrder($artworks, $ids);
    }

    // ------------------------------------------------------------- interne

    /**
     * @param list<Artwork> $artworks
     * @param list<int> $ids
     * @return list<Artwork>
     */
    private static function inRequestedOrder(array $artworks, array $ids): array
    {
        $byId = [];

        foreach ($artworks as $artwork) {
            $byId[$artwork->id] = $artwork;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<Artwork>
     */
    private function hydrateAll(array $rows): array
    {
        /** @var array<int, array{row: array<string, mixed>, translations: array<string, ArtworkTranslation>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $grouped[$id] ??= ['row' => $row, 'translations' => []];

            $locale = (string) $row['locale'];

            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $grouped[$id]['translations'][$locale] = new ArtworkTranslation(
                locale: Locale::from($locale),
                slug: Slug::fromString((string) $row['slug']),
                title: (string) $row['title'],
                eyebrow: self::nullableString($row['eyebrow']),
                description: self::nullableString($row['description']),
                detail: self::nullableString($row['detail']),
                metaTitle: self::nullableString($row['meta_title']),
                metaDescription: self::nullableString($row['meta_description']),
            );
        }

        return array_values(array_map(
            static function (array $data): Artwork {
                $row = $data['row'];

                return new Artwork(
                    id: (int) $row['id'],
                    categoryId: (int) $row['category_id'],
                    seriesId: self::nullableInt($row['series_id']),
                    reference: (string) $row['reference'],
                    year: self::nullableInt($row['year']),
                    technique: self::nullableString($row['technique']),
                    dimensions: Dimensions::fromNullableMillimeters(
                        self::nullableInt($row['width_mm']),
                        self::nullableInt($row['height_mm']),
                    ),
                    isSigned: (bool) $row['is_signed'],
                    price: $row['price_cents'] === null ? null : Money::fromCents((int) $row['price_cents']),
                    status: ArtworkStatus::from((string) $row['status']),
                    weightGrams: self::nullableInt($row['weight_grams']),
                    primaryMediaId: self::nullableInt($row['primary_media_id']),
                    position: (int) $row['position'],
                    translations: new Translations($data['translations']),
                );
            },
            $grouped,
        ));
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
