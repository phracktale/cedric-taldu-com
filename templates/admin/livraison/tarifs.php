<?php

/**
 * Tarifs d'expédition (retours du 2026-09-25, Boutique › Livraisons) : module
 * de livraison, emballage forfaitaire et grille par zone. Une tranche vaut
 * « jusqu'à N kg » ; le colis prend la première tranche qui le contient. Deux
 * lignes vides par zone pour ajouter des tranches sans JavaScript.
 *
 * @var array<string, mixed> $data
 * @var callable             $partial
 */

declare(strict_types=1);

use App\Service\Shipping\Carrier;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var list<Carrier> $transporteurs */
$transporteurs = is_array($data['transporteurs'] ?? null) ? $data['transporteurs'] : [];
$choisi = is_string($data['transporteur'] ?? null) ? $data['transporteur'] : '';
/** @var list<array{id: int, code: string, fr: string, en: string, countries: list<string>, brackets: list<array{grams: int, cents: int, freeAboveCents: int|null}>}> $zones */
$zones = is_array($data['zones'] ?? null) ? $data['zones'] : [];
/** @var list<string> $erreurs */
$erreurs = is_array($data['erreursTarifs'] ?? null) ? $data['erreursTarifs'] : [];
/** @var array<string, string|null> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];
$emballage = is_int($data['emballage'] ?? null) ? $data['emballage'] : 250;

// Valeur à afficher : la saisie refusée d'abord (rien n'est perdu), sinon la base.
$v = static fn (string $nom, string $defaut): string => $saisie === [] ? $defaut : (string) ($saisie[$nom] ?? '');
?>
<section class="admin-bloc" id="tarifs">
    <h2>Tarifs d’expédition</h2>

    <?php if (($data['tarifsEnregistres'] ?? false) === true) : ?>
    <p class="succes" role="status">Les tarifs ont été enregistrés.</p>
    <?php endif; ?>
    <?php if ($erreurs !== []) : ?>
    <div class="erreur" role="alert">
        <p>Rien n’a été enregistré :</p>
        <ul>
            <?php foreach ($erreurs as $erreur) : ?>
            <li><?= e($erreur) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/livraison/tarifs') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <div class="grille-champs">
            <p class="champ">
                <label for="transporteur">Module de livraison</label>
                <select id="transporteur" name="transporteur">
                    <?php foreach ($transporteurs as $transporteur) : ?>
                    <option value="<?= attr($transporteur->code()) ?>"<?php if ($transporteur->code() === $choisi) : ?> selected<?php endif; ?>><?= e($transporteur->name()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php foreach ($transporteurs as $transporteur) : ?>
                <span class="champ-aide">
                    <?= e($transporteur->name()) ?> : suivi des colis ;
                    <?php if ($transporteur->apiEnabled()) : ?>
                    API active (étiquettes, points de retrait).
                    <?php else : ?>
                    API en attente : identifiants absents (contrat à renseigner dans le fichier .env du serveur).
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
            </p>
            <p class="champ">
                <label for="emballage">Emballage (g)</label>
                <input type="number" id="emballage" name="emballage" value="<?= attr($v('emballage', (string) $emballage)) ?>"
                       min="0" max="20000" class="champ-court">
                <span class="champ-aide">Ajouté au poids des œuvres pour choisir la tranche.</span>
            </p>
        </div>

        <?php foreach ($zones as $zone) : ?>
            <?php $p = 'z' . $zone['id']; ?>
        <fieldset class="zone-tarifs">
            <legend><?= e($zone['fr']) ?></legend>
            <div class="grille-champs">
                <p class="champ">
                    <label for="<?= attr($p . '_fr') ?>">Nom (français)</label>
                    <input type="text" id="<?= attr($p . '_fr') ?>" name="<?= attr($p . '_fr') ?>" value="<?= attr($v($p . '_fr', $zone['fr'])) ?>" maxlength="80">
                </p>
                <p class="champ">
                    <label for="<?= attr($p . '_en') ?>">Nom (anglais)</label>
                    <input type="text" id="<?= attr($p . '_en') ?>" name="<?= attr($p . '_en') ?>" value="<?= attr($v($p . '_en', $zone['en'])) ?>" maxlength="80">
                </p>
                <p class="champ">
                    <label for="<?= attr($p . '_pays') ?>">Pays</label>
                    <input type="text" id="<?= attr($p . '_pays') ?>" name="<?= attr($p . '_pays') ?>" value="<?= attr($v($p . '_pays', implode(', ', $zone['countries']))) ?>">
                    <span class="champ-aide">Codes à deux lettres (FR, BE, CH…), ou * pour le reste du monde.</span>
                </p>
            </div>
            <?= $partial('admin/livraison/tranches', ['prefixe' => $p, 'tranches' => $zone['brackets'], 'saisie' => $saisie]) ?>
            <label class="case"><input type="checkbox" name="<?= attr($p . '_suppr') ?>" value="1"<?php if ($v($p . '_suppr', '') === '1') : ?> checked<?php endif; ?>> Supprimer cette zone</label>
        </fieldset>
        <?php endforeach; ?>

        <fieldset class="zone-tarifs">
            <legend>Nouvelle zone</legend>
            <div class="grille-champs">
                <p class="champ">
                    <label for="nz_fr">Nom (français)</label>
                    <input type="text" id="nz_fr" name="nz_fr" maxlength="80" value="<?= attr($v('nz_fr', '')) ?>">
                </p>
                <p class="champ">
                    <label for="nz_en">Nom (anglais)</label>
                    <input type="text" id="nz_en" name="nz_en" maxlength="80" value="<?= attr($v('nz_en', '')) ?>">
                </p>
                <p class="champ">
                    <label for="nz_pays">Pays</label>
                    <input type="text" id="nz_pays" name="nz_pays" value="<?= attr($v('nz_pays', '')) ?>">
                </p>
            </div>
            <?= $partial('admin/livraison/tranches', ['prefixe' => 'nz', 'tranches' => [], 'saisie' => $saisie]) ?>
        </fieldset>

        <p class="actions"><button type="submit" class="bouton">Enregistrer les tarifs</button></p>
    </form>
</section>
