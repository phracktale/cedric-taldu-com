<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Valide et normalise une saisie selon le modèle.
 *
 * - Seuls les champs déclarés sont gardés : pas d'affectation en masse.
 * - Les valeurs venues d'un formulaire (chaînes) prennent leur vrai type :
 *   entier, décimal, booléen.
 * - Un slug vide se déduit de son champ source ; un slug saisi doit être bien
 *   formé (minuscules, chiffres, tirets), jamais un chemin.
 * - Les listes sont validées élément par élément, les arbres récursivement.
 *
 * - Le texte riche est ASSAINI ici, à l'écriture (liste blanche de balises et
 *   d'attributs, vrai analyseur HTML) : le front l'insère tel quel dans la
 *   page publique. La lecture n'a plus rien à nettoyer.
 */
final class EntryValidator
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct(?HtmlSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?? new HtmlSanitizer();
    }

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';
    private const DATETIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/';
    private const MAX_DEPTH = 4;

    /**
     * @param array<mixed> $input saisie brute (formulaire, JSON)
     */
    public function validate(ContentType $type, array $input): ValidationResult
    {
        $errors = [];
        $data = $this->fields($type->fields, $input, '', $errors);

        foreach ($type->children as $name => $child) {
            $data[$name] = $this->list($child, $input[$name] ?? [], $name, $errors, 0);
        }

        return new ValidationResult($data, $errors);
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @param array<mixed> $input
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function fields(array $fields, array $input, string $prefix, array &$errors): array
    {
        $data = [];

        foreach ($fields as $name => $field) {
            $path = $prefix . $name;

            if ($field->type === 'embed') {
                $value = is_array($input[$name] ?? null) ? $input[$name] : [];
                $data[$name] = $this->fields($field->fields, $value, $path . '.', $errors);
                continue;
            }

            [$value, $error] = $this->value($field, $input[$name] ?? null);
            $data[$name] = $value;
            if ($error !== null) {
                $errors[$path] = $error;
            }
        }

        // Slug déduit de sa source quand il est laissé vide.
        foreach ($fields as $name => $field) {
            if ($field->type === 'slug' && $data[$name] === '' && $field->from !== null && is_string($data[$field->from] ?? null)) {
                $data[$name] = self::slugify($data[$field->from]);
                unset($errors[$prefix . $name]);
            }
        }

        return $data;
    }

    /**
     * @param array<string, string> $errors
     * @return list<array<string, mixed>>
     */
    private function list(ChildDefinition $child, mixed $input, string $path, array &$errors, int $depth): array
    {
        if (!is_array($input)) {
            return [];
        }

        $items = [];
        foreach (array_values($input) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $prefix = $path . '.' . $index . '.';
            $data = $this->fields($child->fields, $item, $prefix, $errors);

            if ($child->tree) {
                $data['children'] = $depth + 1 < self::MAX_DEPTH
                    ? $this->list($child, $item['children'] ?? [], $prefix . 'children', $errors, $depth + 1)
                    : [];
            }

            $items[] = $data;
        }

        return $items;
    }

    /**
     * @return array{0: mixed, 1: string|null} valeur normalisée, erreur éventuelle
     */
    private function value(FieldDefinition $field, mixed $raw): array
    {
        if ($field->type === 'boolean') {
            $value = $raw === null || $raw === '' ? ($field->default ?? false) : in_array($raw, [true, 1, '1', 'on', 'true'], true);

            return [(bool) $value, null];
        }

        if ($field->type === 'lines') {
            // Une ligne saisie = un élément (adresse…) ; un import fournit déjà la liste.
            $lines = is_array($raw) ? $raw : preg_split('/\R/', is_string($raw) ? $raw : '');
            $lines = array_values(array_filter(
                array_map(static fn (mixed $l): string => is_scalar($l) ? trim((string) $l) : '', $lines ?: []),
                static fn (string $l): bool => $l !== '',
            ));

            return [$lines, $field->required && $lines === [] ? 'Ce champ est obligatoire.' : null];
        }

        if ($field->type === 'json') {
            if (is_array($raw)) {
                return [$raw, null];
            }
            // Saisie dans l'éditeur : du texte JSON, à décoder.
            $text = is_string($raw) ? trim($raw) : '';
            if ($text === '') {
                return [$field->default ?? null, $field->required ? 'Ce champ est obligatoire.' : null];
            }
            $decoded = json_decode($text, true, 32);

            return json_last_error() === JSON_ERROR_NONE
                ? [$decoded, null]
                : [null, 'JSON invalide.'];
        }

        // Une zone de texte arrive en CRLF (norme HTML) : normaliser, sans quoi
        // enregistrer sans rien changer modifierait le contenu.
        $text = is_scalar($raw) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $raw)) : '';
        if ($text === '' && is_scalar($field->default)) {
            $text = (string) $field->default;
        }

        if ($text === '') {
            $empty = in_array($field->type, ['integer', 'decimal', 'date', 'datetime', 'enum'], true) ? null : '';

            return [$empty, $field->required && $field->type !== 'slug' ? 'Ce champ est obligatoire.' : null];
        }

        return match ($field->type) {
            'integer' => preg_match('/^-?\d+$/', $text) === 1 ? [(int) $text, null] : [null, 'Nombre entier attendu.'],
            'decimal' => is_numeric(str_replace(',', '.', $text)) ? [(float) str_replace(',', '.', $text), null] : [null, 'Nombre attendu.'],
            'email' => filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? [$text, null] : [$text, 'Adresse e-mail invalide.'],
            'url' => self::isUrl($text) ? [$text, null] : [$text, 'Adresse web invalide (https://… ou chemin /…).'],
            'slug' => preg_match(self::SLUG, $text) === 1 ? [$text, null] : [$text, 'Minuscules, chiffres et tirets seulement.'],
            'date' => self::isDate($text) ? [$text, null] : [null, 'Date attendue au format AAAA-MM-JJ.'],
            'datetime' => preg_match(self::DATETIME, $text) === 1 ? [str_replace('T', ' ', $text), null] : [null, 'Date et heure attendues.'],
            'enum' => in_array($text, $field->values, true) ? [$text, null] : [null, 'Valeur non permise.'],
            'richtext' => [$this->sanitizer->sanitize($text), null],
            default => [$text, null],
        };
    }

    private static function isDate(string $text): bool
    {
        if (preg_match(self::DATE, $text) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map(intval(...), explode('-', $text));

        return checkdate($m, $d, $y);
    }

    private static function isUrl(string $text): bool
    {
        if (str_starts_with($text, '/') && !str_starts_with($text, '//')) {
            return true;
        }
        $scheme = parse_url($text, PHP_URL_SCHEME);

        if ($scheme === 'mailto' || $scheme === 'tel') {
            return true;
        }

        return ($scheme === 'http' || $scheme === 'https') && filter_var($text, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Adresse lisible d'un titre : « Ingénieur géotechnicien » → « ingenieur-geotechnicien ».
     */
    public static function slugify(string $text): string
    {
        $map = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'œ' => 'oe',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ];
        $text = strtr(mb_strtolower($text, 'UTF-8'), $map);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }
}
