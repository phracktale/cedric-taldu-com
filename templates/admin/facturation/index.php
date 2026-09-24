<?php

/**
 * Facturation (revue du 2026-09-24) : identité du vendeur sur les factures.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Order\SellerIdentity;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var SellerIdentity $vendeur */
$vendeur = $data['vendeur'];
?>
<div class="admin-page admin-page--etroite">
    <h1>Facturation</h1>

    <p class="aide">
        Ces informations figurent sur chaque facture, téléchargeable par le client depuis son
        espace et par vous depuis la fiche commande. Le numéro de facture est la référence de la
        commande ; la mention de TVA suit le régime de la commande.
        <?php if (!$vendeur->isComplete()) : ?>
        <strong>À compléter : une facture doit porter au moins le nom, l’adresse et le SIRET.</strong>
        <?php endif; ?>
    </p>

    <form method="post" action="<?= attr($base . '/admin/facturation') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="nom">Nom ou raison sociale</label>
            <input type="text" id="nom" name="nom" maxlength="120" value="<?= attr($vendeur->name) ?>">
        </p>
        <p class="champ">
            <label for="adresse">Adresse</label>
            <textarea id="adresse" name="adresse" rows="3" maxlength="400"><?= e($vendeur->address) ?></textarea>
        </p>
        <div class="grille-champs">
            <p class="champ">
                <label for="siret">SIRET</label>
                <input type="text" id="siret" name="siret" maxlength="40" value="<?= attr($vendeur->siret) ?>">
            </p>
            <p class="champ">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" maxlength="190" value="<?= attr($vendeur->email) ?>">
            </p>
        </div>
        <p class="champ">
            <label for="mention">Mention complémentaire</label>
            <input type="text" id="mention" name="mention" maxlength="200" value="<?= attr($vendeur->extra) ?>"
                   placeholder="Maison des artistes n° …">
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer</button>
        </p>
    </form>
</div>
