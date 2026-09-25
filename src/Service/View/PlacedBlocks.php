<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Domain\Catalog\Media;
use App\Domain\Editorial\Block;
use App\Domain\Editorial\ContentBlock;
use App\Domain\Locale;
use App\Repository\ContentBlockRepository;
use App\Repository\MediaRepository;

/**
 * Blocs de la bibliothèque placés dans une page (accueil, template), prêts à
 * rendre : blocs de la langue et médias qu'ils citent, chargés en deux
 * requêtes. Une clef dont le bloc a disparu ne donne rien.
 */
final class PlacedBlocks
{
    public function __construct(
        private readonly ContentBlockRepository $blocks,
        private readonly MediaRepository $medias,
    ) {
    }

    /**
     * @param list<string> $keys clefs de placement (les autres sont ignorées)
     * @return array<int, array{blocks: list<Block>, medias: array<int, Media>}>
     */
    public function load(array $keys, Locale $locale): array
    {
        $ids = [];
        foreach ($keys as $key) {
            $id = ContentBlock::idFromKey($key);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $parBloc = [];
        $tousLesBlocs = [];
        foreach ($this->blocks->findByIds($ids) as $id => $bloc) {
            $parBloc[$id] = $bloc->blocks($locale);
            $tousLesBlocs = [...$tousLesBlocs, ...$parBloc[$id]];
        }

        $medias = $this->medias->findByIds(Block::mediaIdsIn($tousLesBlocs));

        $places = [];
        foreach ($parBloc as $id => $blocs) {
            $places[$id] = ['blocks' => $blocs, 'medias' => $medias];
        }

        return $places;
    }
}
