<?php

/**
 * Contenus › Contact (retour client du 2026-09-29) : titre, introduction et
 * coordonnées affichées en vis-à-vis du formulaire. La carte de la page se
 * règle dans Modules › Carte interactive ; sa place, dans Paramètres ›
 * Templates (modèle « Contact »).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
/** @var array{fr: array{title: string, intro: string}, en: array{title: string, intro: string}, common: array{address: string, phone: string, email: string}} $contact */
$contact = $data['contact'];
/** @var list<string> $erreurs */
$erreurs = is_array($data['erreurs'] ?? null) ? $data['erreurs'] : [];
?>
<div class="admin-page">
    <h1>Contact</h1>

    <p class="aide">
        Le contenu de la page contact. Les coordonnées s’affichent à côté du formulaire ; laissées
        vides, le formulaire reste seul. La carte se règle dans
        <a href="<?= attr($base . '/admin/carte') ?>">Modules › Carte interactive</a>.
    </p>

    <?php if (($data['enregistre'] ?? false) === true) : ?>
    <p class="succes" role="status">La page contact a été enregistrée.</p>
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

    <form method="post" action="<?= attr($base . '/admin/contact') ?>" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <?php foreach (['fr' => 'Français', 'en' => 'Anglais'] as $langue => $libelle) : ?>
        <fieldset>
            <legend><?= e($libelle) ?><?php if ($langue === 'en') : ?> (vide : le français est repris)<?php endif; ?></legend>
            <p class="champ">
                <label for="title_<?= attr($langue) ?>">Titre (vide : « Contact »)</label>
                <input type="text" id="title_<?= attr($langue) ?>" name="title_<?= attr($langue) ?>" value="<?= attr($contact[$langue]['title']) ?>" maxlength="120">
            </p>
            <p class="champ">
                <label for="intro_<?= attr($langue) ?>">Introduction</label>
                <textarea id="intro_<?= attr($langue) ?>" name="intro_<?= attr($langue) ?>" rows="3" maxlength="2000"><?= e($contact[$langue]['intro']) ?></textarea>
            </p>
        </fieldset>
        <?php endforeach; ?>

        <fieldset>
            <legend>Coordonnées</legend>
            <p class="champ">
                <label for="adresse">Adresse (une ligne par ligne d’adresse)</label>
                <textarea id="adresse" name="adresse" rows="3" maxlength="400"><?= e($contact['common']['address']) ?></textarea>
            </p>
            <div class="grille-champs">
                <p class="champ">
                    <label for="telephone">Téléphone</label>
                    <input type="tel" id="telephone" name="telephone" value="<?= attr($contact['common']['phone']) ?>" maxlength="40">
                </p>
                <p class="champ">
                    <label for="email">E-mail affiché</label>
                    <input type="email" id="email" name="email" value="<?= attr($contact['common']['email']) ?>" maxlength="190">
                </p>
            </div>
        </fieldset>

        <p class="actions"><button type="submit" class="bouton">Enregistrer</button></p>
    </form>
</div>
