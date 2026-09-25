<?php

/**
 * Livraison (revue du 2026-09-24) : zone de remise en main propre et état des
 * transporteurs.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Shipping\HandDeliveryZone;
use App\Service\Shipping\Carrier;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var HandDeliveryZone $zone */
$zone = $data['zone'];
/** @var list<Carrier> $transporteurs */
$transporteurs = is_array($data['transporteurs'] ?? null) ? $data['transporteurs'] : [];
?>
<div class="admin-page">
    <h1>Livraison</h1>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/livraison') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <fieldset>
            <legend>Remise en main propre</legend>
            <p class="champ-aide">
                Vous vous déplacez jusqu’à l’acheteur, frais de déplacement offerts, dans ce rayon
                autour de l’atelier. Au-delà, l’œuvre est expédiée. Actuellement :
                <?= e((string) $zone->radiusKm) ?> km autour de <?= e($zone->placeName) ?>.
            </p>
            <p class="champ">
                <label for="adresse">Adresse de l’atelier</label>
                <input type="text" id="adresse" name="adresse" maxlength="190" required>
            </p>
            <div class="grille-champs">
                <p class="champ">
                    <label for="code_postal">Code postal</label>
                    <input type="text" id="code_postal" name="code_postal" maxlength="10" required>
                </p>
                <p class="champ">
                    <label for="lieu">Ville</label>
                    <input type="text" id="lieu" name="lieu" maxlength="80" required value="<?= attr($zone->placeName) ?>">
                </p>
                <p class="champ">
                    <label for="rayon">Rayon (km)</label>
                    <input type="number" id="rayon" name="rayon" min="1" max="200" class="champ-court"
                           value="<?= attr($zone->radiusKm) ?>">
                </p>
            </div>
            <p class="champ-aide">
                L’adresse est localisée par la Base Adresse Nationale ; seules ses coordonnées sont conservées.
            </p>
        </fieldset>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
        </p>
    </form>

    <?= $partial('admin/livraison/tarifs', $data) ?>
</div>
