<?php

/**
 * Tranches de poids d'une zone : les existantes puis deux lignes vides. Une
 * ligne vidée (poids et prix) est retirée à l'enregistrement.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Shipping\ShippingGridForm;

$p = is_string($data['prefixe'] ?? null) ? $data['prefixe'] : 'nz';
/** @var list<array{grams: int, cents: int, freeAboveCents: int|null}> $tranches */
$tranches = is_array($data['tranches'] ?? null) ? $data['tranches'] : [];
/** @var array<string, string|null> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];
$lignes = count($tranches) + 2;
$v = static fn (string $nom, string $defaut): string => $saisie === [] ? $defaut : (string) ($saisie[$nom] ?? '');
?>
<div class="tableau-defilant">
    <table class="tableau tableau-tarifs">
        <caption class="sr-only">Tranches de poids</caption>
        <thead>
            <tr>
                <th scope="col">Jusqu’à (kg)</th>
                <th scope="col">Prix (€)</th>
                <th scope="col">Offert dès (€, facultatif)</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($n = 0; $n < $lignes; $n++) : ?>
                <?php $tranche = $tranches[$n] ?? null; ?>
                <?php $nom = $p . '_t' . $n; ?>
            <tr>
                <td><input type="text" inputmode="decimal" name="<?= attr($nom . '_poids') ?>" value="<?= attr($v($nom . '_poids', $tranche === null ? '' : ShippingGridForm::kilos($tranche['grams']))) ?>" aria-label="Poids maximal en kilos" class="champ-court"></td>
                <td><input type="text" inputmode="decimal" name="<?= attr($nom . '_prix') ?>" value="<?= attr($v($nom . '_prix', $tranche === null ? '' : ShippingGridForm::euros($tranche['cents']))) ?>" aria-label="Prix en euros" class="champ-court"></td>
                <td><input type="text" inputmode="decimal" name="<?= attr($nom . '_franco') ?>" value="<?= attr($v($nom . '_franco', $tranche === null ? '' : ShippingGridForm::euros($tranche['freeAboveCents']))) ?>" aria-label="Offert à partir de, en euros" class="champ-court"></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
</div>
