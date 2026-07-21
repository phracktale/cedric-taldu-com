<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Catalog\Category;
use App\Domain\Catalog\CategoryTranslation;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;
use PDO;

/**
 * Acces aux rubriques.
 *
 * Les traductions sont chargees AVEC la rubrique, en une requete : une requete
 * par rubrique pour lire son titre traduit serait un N+1 present sur toutes les
 * pages du site, le menu Galerie compris.
 */
final class CategoryRepository
{
    /**
     * Colonnes lues, une seule fois. Les rubriques sont toujours relues avec
     * leurs traductions, jamais sans.
     */
    private const SELECT = <<<'SQL'
        SELECT c.id, c.cover_media_id, c.position,
               t.locale, t.slug, t.eyebrow, t.title, t.description, t.method_text,
               t.meta_title, t.meta_description
        FROM categories c
        INNER JOIN category_translations t ON t.category_id = c.id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Rubriques publiees, dans l'ordre choisi par l'artiste.
     *
     * @return list<Category>
     */
    public function findPublished(): array
    {
        $statement = $this->pdo->query(
            self::SELECT . ' WHERE c.is_published = 1 ORDER BY c.position ASC, c.id ASC'
        );

        return $statement === false ? [] : $this->hydrateAll($statement->fetchAll());
    }

    public function findById(int $id): ?Category
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE c.id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrateAll($statement->fetchAll())[0] ?? null;
    }

    /**
     * Rubrique publiee portant ce slug DANS CETTE LANGUE.
     *
     * Le repli de 05-i18n-seo §3 s'applique : une rubrique sans traduction
     * anglaise repond a son slug francais sous /en/. Mais des que la traduction
     * anglaise existe, le slug francais ne repond plus en anglais — sinon la
     * meme page serait accessible a deux URL, dont une non canonique, ce qui
     * est du contenu duplique pour les moteurs.
     *
     * La verification finale porte sur le slug RESOLU pour la langue demandee :
     * c'est elle qui produit ce comportement, sans SQL alambique.
     */
    public function findBySlug(Locale $locale, Slug $slug): ?Category
    {
        $statement = $this->pdo->prepare(
            self::SELECT . <<<'SQL'
             WHERE c.is_published = 1
               AND c.id = (
                   SELECT s.category_id
                   FROM category_translations s
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

        $category = $this->hydrateAll($statement->fetchAll())[0] ?? null;

        if ($category === null) {
            return null;
        }

        return $category->slug($locale)->equals($slug) ? $category : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<Category>
     */
    private function hydrateAll(array $rows): array
    {
        /** @var array<int, array{id: int, cover: int|null, position: int, translations: array<string, CategoryTranslation>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $grouped[$id] ??= [
                'id' => $id,
                'cover' => $row['cover_media_id'] === null ? null : (int) $row['cover_media_id'],
                'position' => (int) $row['position'],
                'translations' => [],
            ];

            $locale = (string) $row['locale'];

            // Une ligne de traduction dans une langue que le site ne sert plus
            // ne doit pas faire tomber la page : on l'ignore.
            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $grouped[$id]['translations'][$locale] = new CategoryTranslation(
                locale: Locale::from($locale),
                slug: Slug::fromString((string) $row['slug']),
                eyebrow: self::nullableString($row['eyebrow']),
                title: (string) $row['title'],
                description: self::nullableString($row['description']),
                metaTitle: self::nullableString($row['meta_title']),
                metaDescription: self::nullableString($row['meta_description']),
                methodText: self::nullableString($row['method_text']),
            );
        }

        return array_values(array_map(
            static fn (array $data): Category => new Category(
                id: $data['id'],
                coverMediaId: $data['cover'],
                position: $data['position'],
                translations: new Translations($data['translations']),
            ),
            $grouped,
        ));
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
