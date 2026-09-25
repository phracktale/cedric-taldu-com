<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Core\ClockInterface;
use App\Domain\Editorial\BlockCatalog;
use App\Domain\Editorial\ContentBlock;
use App\Repository\ContentBlockRepository;

/**
 * Lecture d'une composition postée par un compositeur (accueil, templates),
 * blocs génériques compris (retours du 2026-09-25).
 *
 * Chaque entrée devient une clef : `{type}` pour une section du site,
 * `block:{id}` pour un bloc de la bibliothèque — seulement s'il existe —, et
 * un « Nouveau bloc » (`{type: new, ref: modèle}`) est créé dans la
 * bibliothèque à partir du modèle, assaini comme tout contenu de bloc. La
 * validation des clefs de section reste celle du modèle (HomeLayout,
 * ContentTemplate).
 */
final class BlockPlacement
{
    /** Garde-fou : une composition raisonnable, pas une rafale de créations. */
    private const MAX_NEW = 10;

    public function __construct(
        private readonly ContentBlockRepository $blocks,
        private readonly BlockSanitizer $sanitizer,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string $context lieu du placement, ajouté au nom des blocs créés
     * @return list<string>
     */
    public function keysFromJson(?string $json, string $context): array
    {
        $entrees = json_decode((string) $json, true, 4);
        if (!is_array($entrees)) {
            return [];
        }

        $refs = [];
        foreach ($entrees as $entree) {
            if (is_array($entree) && ($entree['type'] ?? null) === 'block') {
                $id = ContentBlock::idFromKey(ContentBlock::KEY_PREFIX . self::scalar($entree['ref'] ?? null));
                if ($id !== null) {
                    $refs[] = $id;
                }
            }
        }
        $existants = $this->blocks->findByIds($refs);

        $cles = [];
        $crees = 0;
        foreach ($entrees as $entree) {
            if (!is_array($entree)) {
                continue;
            }

            $type = self::scalar($entree['type'] ?? null);
            $ref = self::scalar($entree['ref'] ?? null);

            if ($type === 'block') {
                $id = ContentBlock::idFromKey(ContentBlock::KEY_PREFIX . $ref);
                if ($id !== null && isset($existants[$id])) {
                    $cles[] = $existants[$id]->key();
                }
            } elseif ($type === 'new') {
                $modele = BlockCatalog::presets()[$ref] ?? null;
                if ($modele !== null && $crees < self::MAX_NEW) {
                    $crees++;
                    $cles[] = ContentBlock::KEY_PREFIX . $this->blocks->create(
                        $modele['label'] . ' (' . $context . ')',
                        $this->sanitizer->sanitizeJson(json_encode($modele['blocks'], JSON_THROW_ON_ERROR)),
                        $this->clock->now(),
                    );
                }
            } elseif ($type !== '') {
                $cles[] = $type;
            }
        }

        return $cles;
    }

    private static function scalar(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
