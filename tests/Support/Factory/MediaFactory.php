<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

/**
 * Media de test.
 *
 * La somme de controle et le nom de base sont uniques par construction : ce
 * sont deux contraintes d'unicite du schema, et deux medias de test ne doivent
 * pas les violer par accident.
 */
final class MediaFactory extends Factory
{
    private int $width = 2400;
    private int $height = 3200;
    private ?int $focalX = null;
    private ?int $focalY = null;
    private ?string $basename = null;
    private ?string $copyright = null;

    /** @var array<string, array{alt: string, caption: string|null}> */
    private array $translations = [];

    public function sized(int $width, int $height): self
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function withFocalPoint(int $x, int $y): self
    {
        $this->focalX = $x;
        $this->focalY = $y;

        return $this;
    }

    public function named(string $basename): self
    {
        $this->basename = $basename;

        return $this;
    }

    public function withCopyright(?string $copyright): self
    {
        $this->copyright = $copyright;

        return $this;
    }

    public function translated(string $locale, string $alt, ?string $caption = null): self
    {
        $this->translations[$locale] = ['alt' => $alt, 'caption' => $caption];

        return $this;
    }

    public function create(): int
    {
        $n = self::next();
        $basename = $this->basename ?? 'media-' . $n;

        if ($this->translations === []) {
            $this->translated('fr', 'Description du média ' . $n);
        }

        $this->insert(
            'INSERT INTO media
                (storage_path, public_basename, mime, width, height, bytes, checksum, copyright, focal_x, focal_y, created_at)
             VALUES (:path, :base, :mime, :width, :height, :bytes, :checksum, :copyright, :fx, :fy, NOW())',
            [
                'path' => 'storage/uploads/ab/cd/' . $basename . '.jpg',
                'base' => $basename,
                'mime' => 'image/jpeg',
                'width' => $this->width,
                'height' => $this->height,
                'bytes' => 123456,
                'checksum' => str_pad((string) $n, 64, '0', STR_PAD_LEFT),
                'copyright' => $this->copyright,
                'fx' => $this->focalX,
                'fy' => $this->focalY,
            ],
        );

        $id = $this->lastInsertId();

        foreach ($this->translations as $locale => $translation) {
            $this->insert(
                'INSERT INTO media_translations (media_id, locale, alt, caption)
                 VALUES (:id, :locale, :alt, :caption)',
                [
                    'id' => $id,
                    'locale' => $locale,
                    'alt' => $translation['alt'],
                    'caption' => $translation['caption'],
                ],
            );
        }

        return $id;
    }
}
