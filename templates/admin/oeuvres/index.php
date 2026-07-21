<?php

/**
 * Liste des œuvres.
 *
 * 04-back-office §5 : « liste filtrable (rubrique, serie, statut, publication,
 * recherche) ». Les filtres passent par la CHAINE DE REQUETE : l'URL reste
 * partageable, la page fonctionne sans JavaScript, et le bouton « precedent »
 * du navigateur fait ce qu'on attend.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Catalog\ArtworkStatus;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var list<array<string, mixed>> $oeuvres */
$oeuvres = is_array($data['oeuvres'] ?? null) ? $data['oeuvres'] : [];

/** @var list<array<string, mixed>> $rubriques */
$rubriques = is_array($data['rubriques'] ?? null) ? $data['rubriques'] : [];

$rubriqueChoisie = $data['rubriqueChoisie'] ?? null;
$statutChoisi = $data['statutChoisi'] ?? null;

?>
<div class="admin-page">
    <h1>Œuvres</h1>

    <p class="actions">
        <a class="bouton" href="<?= attr($base . '/admin/oeuvres/nouvelle') ?>">Nouvelle œuvre</a>
    </p>

    <form method="get" action="<?= attr($base . '/admin/oeuvres') ?>" class="formulaire">
        <div class="grille-champs">
            <p class="champ">
                <label for="rubrique">Rubrique</label>
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

    <?php if ($oeuvres === []) : ?>
    <p class="aide">Aucune œuvre ne correspond.</p>
    <?php else : ?>
    <table class="tableau">
        <thead>
            <tr>
                <th scope="col">Référence</th>
                <th scope="col">Titre</th>
                <th scope="col">Statut</th>
                <th scope="col">Publication</th>
                <th scope="col" class="colonne-actions">Ordre</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($oeuvres as $oeuvre) : ?>
            <tr>
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
                <td class="colonne-actions">
                    <form method="post" action="<?= attr($base . '/admin/oeuvres/' . $oeuvre['id'] . '/position') ?>">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" name="direction" value="haut" class="lien-bouton"
                                aria-label="Monter cette œuvre">↑</button>
                        <button type="submit" name="direction" value="bas" class="lien-bouton"
                                aria-label="Descendre cette œuvre">↓</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
