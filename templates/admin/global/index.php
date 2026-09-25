<?php

/**
 * Paramètres › Global (retours du 2026-09-25) : identité du site.
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

use App\Domain\Editorial\SiteIdentity;
use App\Domain\Locale;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var SiteIdentity $identite */
$identite = $data['identite'];
/** @var list<string> $erreurs */
$erreurs = is_array($data['erreurs'] ?? null) ? $data['erreurs'] : [];
/** @var array<string, string> $saisie */
$saisie = is_array($data['saisie'] ?? null) ? $data['saisie'] : [];
$brut = $identite->toArray();
$v = static fn (string $nom, string $defaut): string => $saisie === [] ? $defaut : (string) ($saisie[$nom] ?? '');
?>
<div class="admin-page">
    <h1>Global</h1>

    <p class="aide">
        L’identité du site, reprise partout : en-tête, pied de page, titres des pages pour les
        moteurs, données structurées, e-mails et back-office.
    </p>

    <?php if (($data['enregistre'] ?? false) === true) : ?>
    <p class="succes" role="status">Les paramètres ont été enregistrés.</p>
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

    <form method="post" action="<?= attr($base . '/admin/global') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <fieldset>
            <legend>Artiste</legend>
            <div class="grille-champs">
                <p class="champ">
                    <label for="nom">Nom (obligatoire)</label>
                    <input type="text" id="nom" name="nom" value="<?= attr($v('nom', $identite->name)) ?>" maxlength="80" required>
                </p>
                <p class="champ">
                    <label for="ville">Ville</label>
                    <input type="text" id="ville" name="ville" value="<?= attr($v('ville', $identite->city)) ?>" maxlength="80">
                </p>
                <p class="champ">
                    <label for="depuis">Première année (©)</label>
                    <input type="number" id="depuis" name="depuis" value="<?= attr($v('depuis', (string) $identite->since)) ?>" min="1900" class="champ-court">
                </p>
            </div>
        </fieldset>

        <?php foreach (['fr' => 'Français', 'en' => 'Anglais'] as $langue => $libelle) : ?>
        <fieldset>
            <legend><?= e($libelle) ?><?php if ($langue === 'en') : ?> (vide : le français est repris)<?php endif; ?></legend>
            <p class="champ">
                <label for="accroche_<?= attr($langue) ?>">Accroche sous le nom</label>
                <input type="text" id="accroche_<?= attr($langue) ?>" name="accroche_<?= attr($langue) ?>" value="<?= attr($v('accroche_' . $langue, $brut['tagline'][$langue])) ?>" maxlength="120">
            </p>
            <p class="champ">
                <label for="metier_<?= attr($langue) ?>">Métier (pied de page, données structurées)</label>
                <input type="text" id="metier_<?= attr($langue) ?>" name="metier_<?= attr($langue) ?>" value="<?= attr($v('metier_' . $langue, $brut['role'][$langue])) ?>" maxlength="160">
            </p>
            <p class="champ">
                <label for="titre_accueil_<?= attr($langue) ?>">Titre de l’accueil pour les moteurs</label>
                <input type="text" id="titre_accueil_<?= attr($langue) ?>" name="titre_accueil_<?= attr($langue) ?>" value="<?= attr($v('titre_accueil_' . $langue, $brut['home_title'][$langue])) ?>" maxlength="180"
                       placeholder="<?= attr($identite->name . ' | ' . $identite->role(Locale::from($langue))) ?>">
                <span class="champ-aide">Vide : le nom et le métier.</span>
            </p>
        </fieldset>
        <?php endforeach; ?>

        <fieldset>
            <legend>Réseaux sociaux</legend>
            <?php for ($n = 0; $n < SiteIdentity::MAX_SOCIALS; $n++) : ?>
            <p class="champ">
                <label for="reseau_<?= attr($n) ?>">Adresse <?= e($n + 1) ?></label>
                <input type="url" id="reseau_<?= attr($n) ?>" name="reseau_<?= attr($n) ?>" value="<?= attr($v('reseau_' . $n, $identite->socials[$n] ?? '')) ?>" placeholder="https://www.instagram.com/…">
            </p>
            <?php endfor; ?>
        </fieldset>

        <p class="actions"><button type="submit" class="bouton">Enregistrer</button></p>
    </form>
</div>
