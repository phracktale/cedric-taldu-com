<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use App\Domain\Catalog\ArtworkStatus;

/**
 * Œuvre de test.
 *
 * Forme attendue par tests/CLAUDE.md :
 *   ArtworkFactory::make()->available()->priced(45000)->create()
 *
 * Par defaut : publiee, disponible, tarifee et traduite en francais seulement.
 * C'est le cas nominal du catalogue, et il exerce le repli linguistique.
 */
final class ArtworkFactory extends Factory
{
    private ArtworkStatus $status = ArtworkStatus::Available;
    private bool $published = true;
    private ?int $priceCents = 45000;
    private ?int $year = 2026;
    private ?string $technique = 'Encre de Chine sur papier';
    private ?int $widthMm = 100;
    private ?int $heightMm = 165;
    private bool $signed = true;
    private int $position = 0;
    private ?int $seriesId = null;
    private ?int $primaryMediaId = null;
    private ?string $publishedAt = null;
    private ?string $reference = null;

    /** @var array<string, array{slug: string, title: string, description: string|null, detail: string|null}> */
    private array $translations = [];

    /**
     * Reference d'atelier imposee, pour eprouver la contrainte d'unicite. Par
     * defaut la fabrique en engendre une distincte a chaque appel.
     */
    public function withReference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function status(ArtworkStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function available(): self
    {
        return $this->status(ArtworkStatus::Available);
    }

    public function sold(): self
    {
        return $this->status(ArtworkStatus::Sold);
    }

    public function reserved(): self
    {
        return $this->status(ArtworkStatus::Reserved);
    }

    public function draft(): self
    {
        return $this->status(ArtworkStatus::Draft)->published(false);
    }

    public function notForSale(): self
    {
        return $this->status(ArtworkStatus::NotForSale)->priced(null);
    }

    public function published(bool $published = true): self
    {
        $this->published = $published;

        return $this;
    }

    public function priced(?int $cents): self
    {
        $this->priceCents = $cents;

        return $this;
    }

    public function madeIn(?int $year): self
    {
        $this->year = $year;

        return $this;
    }

    public function usingTechnique(?string $technique): self
    {
        $this->technique = $technique;

        return $this;
    }

    public function measuring(?int $widthMm, ?int $heightMm): self
    {
        $this->widthMm = $widthMm;
        $this->heightMm = $heightMm;

        return $this;
    }

    public function signed(bool $signed = true): self
    {
        $this->signed = $signed;

        return $this;
    }

    public function atPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function inSeries(?int $seriesId): self
    {
        $this->seriesId = $seriesId;

        return $this;
    }

    public function withPrimaryMedia(?int $mediaId): self
    {
        $this->primaryMediaId = $mediaId;

        return $this;
    }

    /**
     * Date de publication explicite : l'ordre des œuvres liees et des dernieres
     * parutions en depend, et il ne doit pas dependre de l'heure du test.
     */
    public function publishedAt(string $instant): self
    {
        $this->publishedAt = $instant;

        return $this;
    }

    public function translated(
        string $locale,
        string $slug,
        string $title,
        ?string $description = null,
        ?string $detail = null,
    ): self {
        $this->translations[$locale] = [
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'detail' => $detail,
        ];

        return $this;
    }

    public function create(int $categoryId): int
    {
        $n = self::next();

        if ($this->translations === []) {
            $this->translated('fr', 'oeuvre-' . $n, 'Œuvre ' . $n);
        }

        $this->insert(
            'INSERT INTO artworks
                (category_id, series_id, reference, year, technique, width_mm, height_mm, is_signed,
                 price_cents, status, weight_grams, primary_media_id, position, is_published, published_at,
                 created_at, updated_at)
             VALUES
                (:category, :series, :reference, :year, :technique, :width, :height, :signed,
                 :price, :status, 120, :media, :position, :published, :published_at, NOW(), NOW())',
            [
                'category' => $categoryId,
                'series' => $this->seriesId,
                'reference' => $this->reference ?? ('CT-TEST-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)),
                'year' => $this->year,
                'technique' => $this->technique,
                'width' => $this->widthMm,
                'height' => $this->heightMm,
                'signed' => $this->signed ? 1 : 0,
                'price' => $this->priceCents,
                'status' => $this->status->value,
                'media' => $this->primaryMediaId,
                'position' => $this->position,
                'published' => $this->published ? 1 : 0,
                'published_at' => $this->published ? ($this->publishedAt ?? '2026-01-01 00:00:00') : null,
            ],
        );

        $id = $this->lastInsertId();

        foreach ($this->translations as $locale => $translation) {
            $this->insert(
                'INSERT INTO artwork_translations
                    (artwork_id, locale, slug, eyebrow, title, description, detail)
                 VALUES (:id, :locale, :slug, :eyebrow, :title, :description, :detail)',
                [
                    'id' => $id,
                    'locale' => $locale,
                    'slug' => $translation['slug'],
                    'eyebrow' => 'Œuvre originale · Pièce unique',
                    'title' => $translation['title'],
                    'description' => $translation['description'],
                    'detail' => $translation['detail'],
                ],
            );
        }

        return $id;
    }
}
