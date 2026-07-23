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
        <thead><tr><th>SKU</th><th>Taille</th><th>Prix</th><th>Stock</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($repro['variants'] as $variant) : ?>
            <tr>
              <td><?= e((string) $variant['sku']) ?></td>
              <td><?= e((string) $variant['size_label']) ?><?php if ($variant['is_framed']) : ?> · encadré<?php endif; ?></td>
              <td><?= e(money(App\Domain\Money::fromCents((int) $variant['price_cents']), App\Domain\Locale::Fr)) ?></td>
              <td><?= e((string) $variant['stock_qty']) ?></td>
              <td>
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
</section>
