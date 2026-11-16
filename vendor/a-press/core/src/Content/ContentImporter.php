<?php

declare(strict_types=1);

namespace APress\Core\Content;

use APress\Core\Repository\PdoEntryRepository;
use DateTimeImmutable;

/**
 * Importe un document au format de l'API (`{ pages: [...], home: {...} }`) :
 * chaque entrée passe par EntryValidator, est créée puis publiée. C'est le
 * chemin de reprise d'un ancien site (seed.json de Saga).
 *
 * Une entrée invalide est signalée et écartée ; les autres passent. Un type
 * unique est mis à jour au lieu d'être dupliqué. Les champs constants
 * (`api.add`) et les dates du document sont ignorés : ils sont calculés.
 */
final class ContentImporter
{
    public function __construct(
        private readonly ContentModel $model,
        private readonly PdoEntryRepository $entries,
        private readonly EntryValidator $validator,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public function import(array $document, DateTimeImmutable $now): ImportReport
    {
        $byKey = [];
        foreach ($this->model->types() as $type) {
            if ($type->apiKey !== null) {
                $byKey[$type->apiKey] = $type;
            }
        }

        $imported = [];
        $errors = [];

        foreach ($document as $key => $value) {
            $type = $byKey[$key] ?? null;
            if ($type === null) {
                $errors[$key] = 'Aucun type du modèle ne porte cette clé d’API.';
                continue;
            }

            $items = $this->items($type, $value);
            foreach ($items as $index => $item) {
                $result = $this->validator->validate($type, $item);
                if (!$result->isValid()) {
                    foreach ($result->errors as $path => $message) {
                        $errors[$key . ($type->single ? '' : '.' . $index) . '.' . $path] = $message;
                    }
                    continue;
                }

                if ($type->single) {
                    $id = $this->entries->single($type->name, null, $now);
                    $this->entries->saveDraft($id, $result->data, null, $now);
                } else {
                    $id = $this->entries->create($type->name, $result->data, null, $now);
                }
                if ($type->publishable) {
                    $this->entries->publish($id, null, $now);
                }
                $imported[$key] = ($imported[$key] ?? 0) + 1;
            }
        }

        return new ImportReport($imported, $errors);
    }

    /**
     * Les éléments à importer pour un type : une liste pour une collection,
     * un seul objet pour un type unique, ou la liste exposée seule (`api.field`).
     *
     * @return list<array<mixed>>
     */
    private function items(ContentType $type, mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ($type->single) {
            return [$type->apiField !== null ? [$type->apiField => $value] : $value];
        }

        return array_values(array_filter($value, is_array(...)));
    }
}
