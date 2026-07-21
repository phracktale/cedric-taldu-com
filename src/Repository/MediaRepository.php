<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Catalog\Media;
use App\Domain\Catalog\MediaTranslation;
use App\Domain\Locale;
use App\Domain\Translations;
use PDO;

/**
 * Acces aux medias.
 *
 * Les medias se chargent PAR LOT : une requete par image sur une grille de
 * vingt-quatre œuvres serait vingt-quatre allers-retours vers la base, sur la
 * page la plus consultee du site.
 */
final class MediaRepository
{
    /**
     * Le « %s » recoit une liste de MARQUEURS engendree a partir du nombre
     * d'identifiants — jamais une donnee. Les valeurs sont liees.
     */
    private const SELECT = <<<'SQL'
        SELECT m.id, m.public_basename, m.mime, m.width, m.height, m.focal_x, m.focal_y,
               t.locale, t.alt, t.caption
        FROM media m
        LEFT JOIN media_translations t ON t.media_id = m.id
        WHERE m.id IN (%s)
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<int> $ids
     * @return array<int, Media> indexe par identifiant
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

        foreach (array_values(array_unique($ids)) as $index => $id) {
            $name = 'id' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        $statement = $this->pdo->prepare(sprintf(self::SELECT, implode(', ', $placeholders)));
        $statement->execute($parameters);

        return $this->hydrateAll($statement->fetchAll());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, Media>
     */
    private function hydrateAll(array $rows): array
    {
        /** @var array<int, array{row: array<string, mixed>, translations: array<string, MediaTranslation>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $grouped[$id] ??= ['row' => $row, 'translations' => []];

            // Jointure a gauche : un media sans aucune traduction existe encore
            // en base tant que le back-office n'a pas impose le texte alternatif.
            if ($row['locale'] === null) {
                continue;
            }

            $locale = (string) $row['locale'];

            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $grouped[$id]['translations'][$locale] = new MediaTranslation(
                locale: Locale::from($locale),
                alt: (string) $row['alt'],
                caption: $row['caption'] === null ? null : (string) $row['caption'],
            );
        }

        $medias = [];

        foreach ($grouped as $id => $data) {
            $row = $data['row'];

            $translations = $data['translations'];

            // Un media sans texte alternatif reste affichable : une image sans
            // alt est un defaut d'accessibilite, pas une raison de faire tomber
            // la page qui la porte.
            if (!isset($translations[Locale::reference()->value])) {
                $translations[Locale::reference()->value] = new MediaTranslation(
                    locale: Locale::reference(),
                    alt: '',
                    caption: null,
                );
            }

            $medias[$id] = new Media(
                id: $id,
                publicBasename: (string) $row['public_basename'],
                mime: (string) $row['mime'],
                width: (int) $row['width'],
                height: (int) $row['height'],
                focalX: $row['focal_x'] === null ? null : (int) $row['focal_x'],
                focalY: $row['focal_y'] === null ? null : (int) $row['focal_y'],
                translations: new Translations($translations),
            );
        }

        return $medias;
    }
}
