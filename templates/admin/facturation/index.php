<?php

/**
 * Facturation (revue du 2026-09-24) : identité du vendeur sur les factures.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Order\SellerIdentity;
use App\Domain\Order\VatMode;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var SellerIdentity $vendeur */
$vendeur = $data['vendeur'];
/** @var array{mode: string|null, taxable_from: string|null} $tva */
$tva = $data['tva'];
$erreurs = is_array($data['erreurs'] ?? null) ? $data['erreurs'] : [];
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];
$regime = is_string($saisie['tva_regime'] ?? null) ? $saisie['tva_regime'] : (string) $tva['mode'];
$depuis = is_string($saisie['tva_depuis'] ?? null) ? $saisie['tva_depuis'] : (string) ($tva['taxable_from'] ?? '');
$mentionFranchise = (string) VatMode::Exempt293b->legalMention(Locale::Fr);
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

    <form method="post" action="<?= attr($base . '/admin/facturation') ?>" data-autosave class="formulaire">
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

    <?php // Régime de TVA (2026-10-28) : 03-boutique §5.1, 04-back-office §11. ?>
    <h2 id="tva">TVA</h2>

    <p class="aide">
        <?php if ($tva['mode'] === 'taxed') : ?>
        Régime en vigueur : <strong>assujetti à la TVA</strong>, pour les commandes passées à partir du
        <?= e((string) $tva['taxable_from']) ?>.
        <?php else : ?>
        Régime en vigueur : <strong>franchise en base</strong>, aucune TVA n’est facturée.
        <?php endif; ?>
        Le régime de chaque commande est figé à sa création : un changement ici ne recalcule jamais
        une commande passée et ne modifie aucun prix affiché (les prix sont toutes taxes comprises).
    </p>

    <form method="post" action="<?= attr($base . '/admin/facturation/tva') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <fieldset>
            <legend>Régime de TVA</legend>
            <p class="champ champ-inline">
                <input type="radio" id="tva_exempt_293b" name="tva_regime" value="exempt_293b"<?php if ($regime === 'exempt_293b') : ?> checked<?php endif; ?>>
                <label for="tva_exempt_293b">Franchise en base : aucune TVA facturée, chaque facture porte
                    la mention « <?= e($mentionFranchise) ?> ».</label>
            </p>
            <p class="champ champ-inline">
                <input type="radio" id="tva_taxed" name="tva_regime" value="taxed"<?php if ($regime === 'taxed') : ?> checked<?php endif; ?>>
                <label for="tva_taxed">Assujetti à la TVA : les commandes passées à partir de la date
                    ci-dessous sont soumises à la TVA, au taux de la catégorie de TVA de chaque œuvre ou
                    reproduction.</label>
            </p>
            <?php if (isset($erreurs['tva_regime'])) : ?>
            <span class="champ-erreur" role="alert"><?= e($erreurs['tva_regime']) ?></span>
            <?php endif; ?>
        </fieldset>

        <p class="champ">
            <label for="tva_depuis">TVA appliquée à partir du</label>
            <input type="date" id="tva_depuis" name="tva_depuis"
                   value="<?= attr($depuis) ?>"<?php if (isset($erreurs['tva_depuis'])) : ?> aria-invalid="true"<?php endif; ?>>
            <?php if (isset($erreurs['tva_depuis'])) : ?>
            <span class="champ-erreur" role="alert"><?= e($erreurs['tva_depuis']) ?></span>
            <?php endif; ?>
            <span class="champ-aide">Seulement pour le régime assujetti : date de bascule fixée avec votre comptable.</span>
        </p>

        <p class="champ champ-inline">
            <input type="checkbox" id="tva_confirmation" name="tva_confirmation" value="1"<?php if (isset($erreurs['tva_confirmation'])) : ?> aria-invalid="true"<?php endif; ?>>
            <label for="tva_confirmation">Je confirme le changement de régime de TVA.</label>
            <?php if (isset($erreurs['tva_confirmation'])) : ?>
            <span class="champ-erreur" role="alert"><?= e($erreurs['tva_confirmation']) ?></span>
            <?php endif; ?>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Enregistrer le régime de TVA</button>
        </p>
    </form>
</div>
