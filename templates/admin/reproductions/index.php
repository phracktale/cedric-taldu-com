<?php

/**
 * Reproductions d'une œuvre (04-back-office, lot 3).
 *
 * Chaque reproduction porte ses variantes (taille, encadrement, prix, stock).
 * Le prix est saisi en euros ; le controleur le convertit en centimes entiers.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
$artworkId = is_int($data['artworkId'] ?? null) ? $data['artworkId'] : 0;
$artworkTitle = is_string($data['artworkTitle'] ?? null) ? $data['artworkTitle'] : '';
/** @var list<array<string, mixed>> $reproductions */
$reproductions = is_array($data['reproductions'] ?? null) ? $data['reproductions'] : [];

$reproUrl = $base . '/admin/oeuvres/' . $artworkId . '/reproductions';
?>
<section class="admin-reproductions">
  <p><a href="<?= attr($base) ?>/admin/oeuvres/<?= attr($artworkId) ?>">← Retour à l’œuvre</a></p>
  <h1>Reproductions — <?= e($artworkTitle) ?></h1>

  <?php foreach ($reproductions as $repro) : ?>
    <article class="admin-repro">
      <header>
        <h2><?= e((string) $repro['title']) ?></h2>
        <p>
          <?= e($repro['kind'] === 'limited' ? 'Édition limitée' : 'Tirage courant') ?>
          <?php if ($repro['edition_size'] !== null) : ?>
            — <?= e((string) $repro['editions_sold']) ?>/<?= e((string) $repro['edition_size']) ?> vendus
          <?php endif; ?>
          — <?= e($repro['is_published'] ? 'Publiée' : 'Brouillon') ?>
        </p>
      </header>

      <table class="admin-table">
        <thead><tr><th>SKU</th><th>SKU Prodigi</th><th>Taille</th><th>Prix</th><th>Stock</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($repro['variants'] as $variant) : ?>
            <?php $sizing = (string) ($variant['prodigi_sizing'] ?? 'fillPrintArea'); ?>
            <tr>
              <td><?= e((string) $variant['sku']) ?></td>
              <td><?= e((string) ($variant['prodigi_sku'] ?? '—')) ?></td>
              <td><?= e((string) $variant['size_label']) ?><?php if ($variant['is_framed']) : ?> · encadré<?php endif; ?></td>
              <td><?= e(money(App\Domain\Money::fromCents((int) $variant['price_cents']), App\Domain\Locale::Fr)) ?></td>
              <td><?= e((string) $variant['stock_qty']) ?></td>
              <td class="admin-repro-var-actions">
                <details>
                  <summary>Modifier</summary>
                  <form method="post" action="<?= attr($base) ?>/admin/variantes/<?= attr($variant['id']) ?>">
                    <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                    <label>SKU <input type="text" name="sku" required maxlength="60" value="<?= attr($variant['sku']) ?>"></label>
                    <label>Taille <input type="text" name="taille" required maxlength="60" value="<?= attr($variant['size_label']) ?>"></label>
                    <label>Encadré <input type="checkbox" name="encadre" value="1"<?php if ($variant['is_framed']) : ?> checked<?php endif; ?>></label>
                    <label>Prix (€) <input type="text" name="prix" required inputmode="decimal" value="<?= attr(sprintf('%d.%02d', intdiv((int) $variant['price_cents'], 100), (int) $variant['price_cents'] % 100)) ?>"></label>
                    <label>Stock <input type="number" name="stock" min="0" required value="<?= attr($variant['stock_qty']) ?>"></label>
                    <label>Poids (g) <input type="number" name="poids" min="0" required value="<?= attr($variant['weight_grams']) ?>"></label>
                    <label>SKU Prodigi <input type="text" name="prodigi_sku" maxlength="60" list="prodigi-skus" value="<?= attr($variant['prodigi_sku'] ?? '') ?>" placeholder="ex. GLOBAL-HGE-16X20"></label>
                    <label>Cadrage
                      <select name="prodigi_sizing">
                        <option value="fillPrintArea"<?php if ($sizing === 'fillPrintArea') : ?> selected<?php endif; ?>>Remplir (recadre)</option>
                        <option value="fitPrintArea"<?php if ($sizing === 'fitPrintArea') : ?> selected<?php endif; ?>>Contenir (marge)</option>
                        <option value="stretchToPrintArea"<?php if ($sizing === 'stretchToPrintArea') : ?> selected<?php endif; ?>>Étirer</option>
                      </select>
                    </label>
                    <button type="submit">Enregistrer</button>
                  </form>
                </details>
                <form method="post" action="<?= attr($base) ?>/admin/variantes/<?= attr($variant['id']) ?>/suppression">
                  <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
                  <button type="submit">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <details>
        <summary>Ajouter une variante</summary>
        <form method="post" action="<?= attr($base) ?>/admin/reproductions/<?= attr($repro['id']) ?>/variantes">
          <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
          <label>SKU <input type="text" name="sku" required maxlength="60"></label>
          <label>Taille <input type="text" name="taille" required maxlength="60"></label>
          <label>Encadré <input type="checkbox" name="encadre" value="1"></label>
          <label>Prix (€) <input type="text" name="prix" required inputmode="decimal"></label>
          <label>Stock <input type="number" name="stock" min="0" value="0" required></label>
          <label>Poids (g) <input type="number" name="poids" min="0" value="300" required></label>
          <label>SKU Prodigi <input type="text" name="prodigi_sku" maxlength="60" list="prodigi-skus" placeholder="ex. GLOBAL-HGE-16X20"></label>
          <label>Cadrage
            <select name="prodigi_sizing">
              <option value="fillPrintArea">Remplir (recadre)</option>
              <option value="fitPrintArea">Contenir (marge)</option>
              <option value="stretchToPrintArea">Étirer</option>
            </select>
          </label>
          <button type="submit">Ajouter</button>
        </form>
      </details>

      <div class="admin-repro-actions">
        <form method="post" action="<?= attr($base) ?>/admin/reproductions/<?= attr($repro['id']) ?>/publication">
          <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
          <button type="submit"><?= e($repro['is_published'] ? 'Dépublier' : 'Publier') ?></button>
        </form>
        <form method="post" action="<?= attr($base) ?>/admin/reproductions/<?= attr($repro['id']) ?>/suppression">
          <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
          <button type="submit">Supprimer la reproduction</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>

  <h2>Nouvelle reproduction</h2>
  <form method="post" action="<?= attr($reproUrl) ?>">
    <input type="hidden" name="_token" value="<?= attr($jeton) ?>">
    <label>Titre <input type="text" name="titre" required maxlength="200"></label>
    <label>Genre
      <select name="genre">
        <option value="standard">Tirage courant</option>
        <option value="limited">Édition limitée</option>
      </select>
    </label>
    <label>Taille d’édition (si limitée) <input type="number" name="taille_edition" min="1"></label>
    <button type="submit" class="btn btn-plein">Créer</button>
  </form>

  <?php // Suggestions de SKU Prodigi (Hahnemühle German Etching), cliquables dans les champs ci-dessus. ?>
  <datalist id="prodigi-skus">
    <option value="GLOBAL-HGE-12X16"></option>
    <option value="GLOBAL-HGE-16X20"></option>
    <option value="GLOBAL-HGE-24X36"></option>
  </datalist>
</section>
