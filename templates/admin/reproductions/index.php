<?php

/**
 * Reproductions d'une œuvre (04-back-office).
 *
 * L'artiste propose des tirages en choisissant, dans la liste des SKU Prodigi
 * gérés, les tailles qu'il vend et leur prix. Le SKU Prodigi, le cadrage, le
 * libellé de taille et le poids viennent du catalogue : aucune saisie technique,
 * aucun titre. Les variantes existantes se corrigent (prix, stock) ou se
 * suppriment ligne à ligne.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Shop\ManagedReproductions;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$artworkId = is_int($data['artworkId'] ?? null) ? $data['artworkId'] : 0;
$artworkTitle = is_string($data['artworkTitle'] ?? null) ? $data['artworkTitle'] : '';
/** @var list<array<string, mixed>> $reproductions */
$reproductions = is_array($data['reproductions'] ?? null) ? $data['reproductions'] : [];

$reproUrl = $base . '/admin/oeuvres/' . $artworkId . '/reproductions';

// Tailles déjà proposées (par SKU Prodigi), pour ne pas les reproposer.
$dejaProposes = [];
foreach ($reproductions as $repro) {
    foreach ($repro['variants'] as $variant) {
        if (is_string($variant['prodigi_sku'] ?? null) && $variant['prodigi_sku'] !== '') {
            $dejaProposes[$variant['prodigi_sku']] = true;
        }
    }
}

$prixEuros = static fn (int $cents): string => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
?>
<div class="admin-page">
    <p class="actions">
        <a class="bouton bouton--secondaire" href="<?= attr($base) ?>/admin/oeuvres/<?= attr($artworkId) ?>">
            ← Retour à l’œuvre
        </a>
    </p>

    <h1>Reproductions — <?= e($artworkTitle) ?></h1>

    <section class="admin-bloc">
        <h2>Ajouter des tirages</h2>
        <p class="aide">
            Cochez une taille en indiquant son prix TTC : le tirage est créé et envoyé à
            l’imprimeur au format correspondant. Laissez vide pour ne pas la proposer.
        </p>

        <form method="post" action="<?= attr($reproUrl) ?>" class="formulaire">
            <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

            <div class="grille-champs">
                <?php foreach (ManagedReproductions::all() as $tirage) : ?>
                    <?php $ajoute = isset($dejaProposes[$tirage['sku']]); ?>
                    <p class="champ">
                        <label for="<?= attr($tirage['field']) ?>">
                            <?= e($tirage['size']) ?>
                            <span class="champ-aide"><?= e($tirage['sku']) ?></span>
                        </label>
                        <?php if ($ajoute) : ?>
                            <input type="text" id="<?= attr($tirage['field']) ?>" value="Déjà proposé" disabled>
                        <?php else : ?>
                            <input type="text" id="<?= attr($tirage['field']) ?>" name="<?= attr($tirage['field']) ?>"
                                   inputmode="decimal" placeholder="prix TTC en euros">
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            </div>

            <p class="actions">
                <button type="submit" class="bouton">Ajouter les tirages</button>
            </p>
        </form>
    </section>

    <?php if ($reproductions === []) : ?>
        <p class="aide">Aucun tirage pour cette œuvre. Ajoutez-en une taille ci-dessus.</p>
    <?php endif; ?>

    <?php foreach ($reproductions as $repro) : ?>
        <section class="admin-bloc">
            <div class="admin-bloc-tete">
                <h2>
                    <?= e((string) $repro['title']) ?>
                    <?php if ($repro['is_published']) : ?>
                        <span class="pastille pastille--publie">Publiée</span>
                    <?php else : ?>
                        <span class="pastille pastille--brouillon">Non publiée</span>
                    <?php endif; ?>
                </h2>
                <div class="actions">
                    <form method="post" action="<?= attr($base) ?>/admin/reproductions/<?= attr($repro['id']) ?>/publication">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="bouton bouton--secondaire">
                            <?= e($repro['is_published'] ? 'Dépublier' : 'Publier') ?>
                        </button>
                    </form>
                    <form method="post" action="<?= attr($base) ?>/admin/reproductions/<?= attr($repro['id']) ?>/suppression"
                          data-confirmation="Supprimer ce tirage et toutes ses tailles ?">
                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                        <button type="submit" class="lien-bouton">Supprimer le tirage</button>
                    </form>
                </div>
            </div>

            <?php if ($repro['variants'] === []) : ?>
                <p class="aide">Aucune taille. Ajoutez-en une ci-dessus.</p>
            <?php else : ?>
            <table class="tableau">
                <thead>
                    <tr>
                        <th scope="col">Taille</th>
                        <th scope="col">SKU Prodigi</th>
                        <th scope="col">Prix</th>
                        <th scope="col">Stock</th>
                        <th scope="col" class="colonne-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repro['variants'] as $variant) : ?>
                        <?php $sizing = (string) ($variant['prodigi_sizing'] ?? 'fillPrintArea'); ?>
                        <tr>
                            <td>
                                <?= e((string) $variant['size_label']) ?>
                                <?php if ($variant['is_framed']) : ?> · encadré<?php endif; ?>
                            </td>
                            <td><code><?= e((string) ($variant['prodigi_sku'] ?? '—')) ?></code></td>
                            <td><?= e(money(Money::fromCents((int) $variant['price_cents']), Locale::Fr)) ?></td>
                            <td><?= e((string) $variant['stock_qty']) ?></td>
                            <td class="colonne-actions">
                                <details>
                                    <summary class="lien-bouton">Modifier</summary>
                                    <form method="post" action="<?= attr($base) ?>/admin/variantes/<?= attr($variant['id']) ?>"
                                          class="formulaire admin-variante-edition">
                                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                                        <div class="grille-champs">
                                            <p class="champ">
                                                <label>Taille
                                                    <input type="text" name="taille" required maxlength="60"
                                                           value="<?= attr($variant['size_label']) ?>">
                                                </label>
                                            </p>
                                            <p class="champ">
                                                <label>SKU boutique
                                                    <input type="text" name="sku" required maxlength="60"
                                                           value="<?= attr($variant['sku']) ?>">
                                                </label>
                                            </p>
                                            <p class="champ">
                                                <label>Prix (€)
                                                    <input type="text" name="prix" required inputmode="decimal"
                                                           value="<?= attr($prixEuros((int) $variant['price_cents'])) ?>">
                                                </label>
                                            </p>
                                            <p class="champ">
                                                <label>Stock
                                                    <input type="number" name="stock" min="0" required
                                                           value="<?= attr($variant['stock_qty']) ?>">
                                                </label>
                                            </p>
                                            <p class="champ">
                                                <label>Poids (g)
                                                    <input type="number" name="poids" min="0" required
                                                           value="<?= attr($variant['weight_grams']) ?>">
                                                </label>
                                            </p>
                                            <p class="champ">
                                                <label>SKU Prodigi
                                                    <input type="text" name="prodigi_sku" maxlength="60" list="prodigi-skus"
                                                           value="<?= attr($variant['prodigi_sku'] ?? '') ?>">
                                                </label>
                                            </p>
                                            <p class="champ">
                                                <label>Cadrage
                                                    <select name="prodigi_sizing">
                                                        <option value="fillPrintArea"<?php if ($sizing === 'fillPrintArea') : ?> selected<?php endif; ?>>Remplir (recadre)</option>
                                                        <option value="fitPrintArea"<?php if ($sizing === 'fitPrintArea') : ?> selected<?php endif; ?>>Contenir (marge)</option>
                                                        <option value="stretchToPrintArea"<?php if ($sizing === 'stretchToPrintArea') : ?> selected<?php endif; ?>>Étirer</option>
                                                    </select>
                                                </label>
                                            </p>
                                        </div>
                                        <p class="actions">
                                            <button type="submit" class="bouton">Enregistrer</button>
                                        </p>
                                    </form>
                                    <form method="post"
                                          action="<?= attr($base) ?>/admin/variantes/<?= attr($variant['id']) ?>/suppression"
                                          data-confirmation="Supprimer cette taille ?">
                                        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                                        <button type="submit" class="lien-bouton">Supprimer la taille</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php // SKU Prodigi gérés (Hahnemühle German Etching), suggérés à l'édition d'une variante. ?>
    <datalist id="prodigi-skus">
        <?php foreach (ManagedReproductions::all() as $tirage) : ?>
            <option value="<?= attr($tirage['sku']) ?>"></option>
        <?php endforeach; ?>
    </datalist>
</div>
