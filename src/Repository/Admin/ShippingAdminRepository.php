<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use PDO;
use Throwable;

/**
 * Grille de port en back-office (retours du 2026-09-25, Boutique ›
 * Livraisons) : lecture pour l'écran, enregistrement en une transaction —
 * une grille est cohérente ou n'est pas touchée.
 *
 * @phpstan-import-type Zone from \App\Domain\Shipping\ShippingGridForm
 */
final class ShippingAdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{id: int, code: string, fr: string, en: string, countries: list<string>, brackets: list<array{grams: int, cents: int, freeAboveCents: int|null}>}>
     */
    public function zones(): array
    {
        $statement = $this->pdo->query(
            'SELECT z.id, z.code, z.label_fr, z.label_en, z.countries,
                    r.max_weight_grams, r.price_cents, r.free_above_cents
               FROM shipping_zones z
               LEFT JOIN shipping_rates r ON r.zone_id = z.id
              ORDER BY z.position ASC, z.id ASC, r.max_weight_grams ASC'
        );

        $zones = [];
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $id = (int) $ligne['id'];
            if (!isset($zones[$id])) {
                $pays = json_decode((string) $ligne['countries'], true);
                $zones[$id] = [
                    'id' => $id,
                    'code' => (string) $ligne['code'],
                    'fr' => (string) $ligne['label_fr'],
                    'en' => (string) $ligne['label_en'],
                    'countries' => is_array($pays) ? array_values(array_filter($pays, 'is_string')) : [],
                    'brackets' => [],
                ];
            }

            if ($ligne['max_weight_grams'] !== null) {
                $zones[$id]['brackets'][] = [
                    'grams' => (int) $ligne['max_weight_grams'],
                    'cents' => (int) $ligne['price_cents'],
                    'freeAboveCents' => $ligne['free_above_cents'] === null ? null : (int) $ligne['free_above_cents'],
                ];
            }
        }

        return array_values($zones);
    }

    /**
     * @param list<Zone> $zones grille validée (ShippingGridForm, sans erreur)
     */
    public function save(array $zones): void
    {
        // Transaction propre, sauf si l'appelant en tient déjà une (même règle
        // que ProductAdminRepository).
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $position = 10;
            foreach ($zones as $zone) {
                if ($zone['delete']) {
                    // Les tranches suivent (ON DELETE CASCADE).
                    $this->pdo->prepare('DELETE FROM shipping_zones WHERE id = :id')->execute(['id' => $zone['id']]);
                    continue;
                }

                $id = $zone['id'] ?? $this->create($zone['fr']);
                $this->pdo->prepare(
                    'UPDATE shipping_zones SET label_fr = :fr, label_en = :en, countries = :countries, position = :position WHERE id = :id'
                )->execute([
                    'fr' => $zone['fr'],
                    'en' => $zone['en'],
                    'countries' => json_encode($zone['countries'], JSON_THROW_ON_ERROR),
                    'position' => $position,
                    'id' => $id,
                ]);
                $position += 10;

                $this->pdo->prepare('DELETE FROM shipping_rates WHERE zone_id = :id')->execute(['id' => $id]);
                $insertion = $this->pdo->prepare(
                    'INSERT INTO shipping_rates (zone_id, max_weight_grams, price_cents, free_above_cents)
                     VALUES (:zone, :grams, :cents, :free)'
                );
                foreach ($zone['brackets'] as $tranche) {
                    $insertion->execute([
                        'zone' => $id,
                        'grams' => $tranche['grams'],
                        'cents' => $tranche['cents'],
                        'free' => $tranche['freeAboveCents'],
                    ]);
                }
            }

            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Nouvelle zone : code technique tiré du nom, rendu unique.
     */
    private function create(string $label): int
    {
        $base = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper(
            (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label),
        )), 0, 14);
        $base = $base === '' ? 'ZONE' : $base;

        $code = $base;
        $existe = $this->pdo->prepare('SELECT COUNT(*) FROM shipping_zones WHERE code = :code');
        for ($n = 2; $existe->execute(['code' => $code]) && (int) $existe->fetchColumn() > 0; $n++) {
            $code = $base . '-' . $n;
        }

        $this->pdo->prepare(
            "INSERT INTO shipping_zones (code, label_fr, label_en, countries, position) VALUES (:code, :fr, :fr2, '[]', 999)"
        )->execute(['code' => $code, 'fr' => $label, 'fr2' => $label]);

        return (int) $this->pdo->lastInsertId();
    }
}
