<?php

/**
 * Liste des œuvres.
 *
 * 04-back-office §5 : « liste filtrable (rubrique, serie, statut, publication,
 * recherche) ». Les filtres passent par la CHAINE DE REQUETE : l'URL reste
 * partageable, la page fonctionne sans JavaScript, et le bouton « precedent »
 * du navigateur fait ce qu'on attend.
 *
 * Classement (demande du 2026-10-12) : la liste montre l'ordre de la page
 * publique correspondante — sans filtre de galerie, celui de « Toutes les
 * œuvres » dans le classement choisi ici ; filtrée, celui de la galerie. Quand
 * cet ordre se règle à la main (`portee`), chaque ligne porte une poignée de
 * glisser-déposer (module admin-tri.js) et les flèches ↑ ↓, qui marchent sans
 * JavaScript, au clavier et au doigt.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\ArtworkStatus;
use App\Domain\Catalog\WorksSort;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var list<array<string, mixed>> $oeuvres */
$oeuvres = is_array($data['oeuvres'] ?? null) ? $data['oeuvres'] : [];

/** @var list<array<string, mixed>> $rubriques */
$rubriques = is_array($data['rubriques'] ?? null) ? $data['rubriques'] : [];

$rubriqueChoisie = $data['rubriqueChoisie'] ?? null;
$statutChoisi = $data['statutChoisi'] ?? null;

$classement = ($data['classement'] ?? null) instanceof WorksSort ? $data['classement'] : WorksSort::Manual;
// « toutes » (ordre libre), l'identifiant d'une galerie, ou null : rien à déplacer.
$portee = is_string($data['portee'] ?? null) ? $data['portee'] : null;

?>
<div class="admin-page">
    <h1>Œuvres</h1>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>
    <?php if (($data['ordreEnregistre'] ?? false) === true) : ?>
    <p class="succes" role="status">L’ordre a été enregistré.</p>
    <?php endif; ?>
    <?php if (($data['classementEnregistre'] ?? false) === true) : ?>
    <p class="succes" role="status">Le classement de la page « Toutes les œuvres » a été enregistré.</p>
    <?php endif; ?>

    <p class="actions">
        <a class="bouton" href="<?= attr($base . '/admin/oeuvres/nouvelle') ?>">Nouvelle œuvre</a>
    </p>

    <form method="get" action="<?= attr($base . '/admin/oeuvres') ?>" class="formulaire">
        <div class="grille-champs">
            <p class="champ">
                <label for="rubrique">Galerie</label>
                <select id="rubrique" name="rubrique">
                    <option value="">Toutes</option>
                    <?php foreach ($rubriques as $rubrique) : ?>
                    <option value="<?= attr($rubrique['id']) ?>"
                        <?php if ((int) $rubriqueChoisie === (int) $rubrique['id']) : ?>selected<?php endif; ?>
                    ><?= e($rubrique['translations']['fr']['title'] ?? 'Sans titre') ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="champ">
                <label for="statut">Statut</label>
                <select id="statut" name="statut">
                    <option value="">Tous</option>
                    <?php foreach (ArtworkStatus::cases() as $statut) : ?>
                    <option value="<?= attr($statut->value) ?>"
                        <?php if ($statutChoisi === $statut->value) : ?>selected<?php endif; ?>
                    ><?= e($statut->label(App\Domain\Locale::Fr)) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>

        <p class="actions">
            <button type="submit" class="bouton bouton--secondaire">Filtrer</button>
        </p>
    </form>

    <?php // Classement de la page publique « Toutes les œuvres » (demande du 2026-10-12). ?>
    <form method="post" action="<?= attr($base . '/admin/oeuvres/classement') ?>" class="formulaire classement-oeuvres">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
        <fieldset>
            <legend>Classement de la page « Toutes les œuvres »</legend>
            <?php foreach (WorksSort::cases() as $mode) : ?>
            <p class="champ-choix">
                <input type="radio" id="mode_<?= attr($mode->value) ?>" name="mode" value="<?= attr($mode->value) ?>"<?php if ($mode === $classement) : ?> checked<?php endif; ?>>
                <label for="mode_<?= attr($mode->value) ?>"><?= e($mode->label()) ?></label>
            </p>
            <?php endforeach; ?>
            <p class="champ-aide">
                Chaque galerie garde son propre ordre : choisissez une galerie dans le filtre ci-dessus pour le régler.
            </p>
            <p class="actions">
                <button type="submit" class="bouton bouton--secondaire">Enregistrer le classement</button>
            </p>
        </fieldset>
    </form>

    <?php if ($oeuvres === []) : ?>
    <p class="aide">Aucune œuvre ne correspond.</p>
    <?php else : ?>
        <?php if ($portee === 'toutes') : ?>
    <p class="aide">
        Cette liste est dans l’ordre de la page « Toutes les œuvres ». Pour le changer, faites glisser
        une ligne par sa poignée, ou utilisez les flèches.
    </p>
        <?php elseif ($portee !== null) : ?>
    <p class="aide">
        Cette liste est dans l’ordre de la galerie. Pour le changer, faites glisser une ligne par sa
        poignée, ou utilisez les flèches.
    </p>
        <?php elseif ($statutChoisi !== null) : ?>
    <p class="aide">Sous un filtre de statut, l’ordre ne se règle pas : retirez le filtre pour déplacer les œuvres.</p>
        <?php else : ?>
    <p class="aide">
        Cette liste est dans l’ordre de la page « Toutes les œuvres », classement automatique :
        <?= e(mb_strtolower($classement->label())) ?>. Pour le régler à la main, choisissez l’ordre libre.
    </p>
        <?php endif; ?>

        <?php if ($portee !== null) : ?>
    <?php // Rempli puis posté par admin-tri.js après un glisser-déposer. ?>
    <form method="post" action="<?= attr($base . '/admin/oeuvres/ordre') ?>" data-tri-form hidden>
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
        <input type="hidden" name="portee" value="<?= attr($portee) ?>">
        <input type="hidden" name="ordre" value="">
    </form>
        <?php endif; ?>

    <table class="tableau"<?php if ($portee !== null) : ?> data-tri<?php endif; ?>>
        <thead>
            <tr>
                <?php if ($portee !== null) : ?>
                <th scope="col" class="tri-colonne"><span class="sr-only">Déplacer</span></th>
                <?php endif; ?>
                <th scope="col">Référence</th>
                <th scope="col">Titre</th>
                <th scope="col">Statut</th>
                <th scope="col">Publication</th>
                <?php if ($portee !== null) : ?>
                <th scope="col" class="colonne-actions">Ordre</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($oeuvres as $oeuvre) : ?>
            <tr<?php if ($portee !== null) : ?> data-tri-id="<?= attr($oeuvre['id']) ?>"<?php endif; ?>>
                <?php if ($portee !== null) : ?>
                <td class="tri-colonne"><span class="tri-poignee" data-tri-poignee title="Faire glisser pour déplacer" aria-hidden="true">⠿</span></td>
                <?php endif; ?>
                <td><code><?= e($oeuvre['reference']) ?></code></td>
                <td>
                    <a href="<?= attr($base . '/admin/oeuvres/' . $oeuvre['id']) ?>"><?= e($oeuvre['translations']['fr']['title'] ?? 'Sans titre') ?></a>
                </td>
                <td><?= e(ArtworkStatus::from($oeuvre['status'])->label(App\Domain\Locale::Fr)) ?></td>
                <td>
                    <?php if ($oeuvre['is_published'] === true) : ?>
                    <span class="pastille pastille--publie">Publiée</span>
                    <?php else : ?>
                    <span class="pastille pastille--brouillon">Non publiée</span>
                    <?php endif; ?>
                </td>
                <?php if ($portee !== null) : ?>
                <td class="colonne-actions">
                    <form method="post" action="<?= attr($base . '/admin/oeuvres/' . $oeuvre['id'] . '/position') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <input type="hidden" name="portee" value="<?= attr($portee) ?>">
                        <button type="submit" name="direction" value="haut" class="lien-bouton"
                                aria-label="Monter cette œuvre">↑</button>
                        <button type="submit" name="direction" value="bas" class="lien-bouton"
                                aria-label="Descendre cette œuvre">↓</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
