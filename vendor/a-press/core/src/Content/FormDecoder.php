<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Reconstruit une saisie structurée à partir des champs à plat d'un formulaire.
 *
 * Request ne garde que des valeurs scalaires (aucun tableau injecté par un
 * nom à crochets) : l'éditeur nomme donc ses champs avec « -- » comme
 * séparateur. Le point est exclu : PHP le change en « _ » dans les noms.
 *
 *   title                       → ['title' => …]
 *   seo--metaTitle              → ['seo' => ['metaTitle' => …]]
 *   missions--0--label          → ['missions' => [0 => ['label' => …]]]
 *   items--0--children--1--href → arbre du menu
 *
 * Les lignes de liste entièrement vides sont retirées : l'éditeur en affiche
 * toujours une de plus, pour ajouter sans JavaScript.
 */
final class FormDecoder
{
    public const SEPARATOR = '--';
    private const MAX_DEPTH = 10;

    /**
     * @param array<string, string> $post
     * @return array<string, mixed>
     */
    public static function decode(array $post): array
    {
        $data = [];

        foreach ($post as $name => $value) {
            if (str_starts_with($name, '_')) {
                continue;
            }
            $path = explode(self::SEPARATOR, $name);
            if (count($path) > self::MAX_DEPTH || in_array('', $path, true)) {
                continue;
            }

            $cursor = &$data;
            foreach ($path as $i => $segment) {
                $key = ctype_digit($segment) ? (int) $segment : $segment;
                if ($i === count($path) - 1) {
                    $cursor[$key] = $value;
                } else {
                    if (!isset($cursor[$key]) || !is_array($cursor[$key])) {
                        $cursor[$key] = [];
                    }
                    $cursor = &$cursor[$key];
                }
            }
            unset($cursor);
        }

        /** @var array<string, mixed> */
        return self::dropEmptyRows($data);
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function dropEmptyRows(array $value): array
    {
        $isList = $value !== [] && array_keys($value) === array_filter(array_keys($value), is_int(...));
        $out = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = self::dropEmptyRows($item);
                if ($isList && self::isEmpty($item)) {
                    continue;
                }
            }
            $out[$key] = $item;
        }

        if ($isList) {
            ksort($out);

            return array_values($out);
        }

        return $out;
    }

    private static function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isEmpty($item)) {
                    return false;
                }
            }

            return true;
        }

        return trim((string) $value) === '';
    }
}
