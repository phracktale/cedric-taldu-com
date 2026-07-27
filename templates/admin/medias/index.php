<?php

/**
 * Mediatheque.
 *
 * Le champ de televersement est un `<input type="file" multiple>` ordinaire :
 * le glisser-deposer de 04-back-office §7 est une amelioration du module JS, et
 * la page fonctionne entierement sans lui (§12).
 *
 * Les vignettes pointent le derive de 320 px, produit pour toutes les images
 * quelle que soit leur taille (Media::availableWidths()).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

/** @var list<array<string, mixed>> $medias */
$medias = is_array($data['medias'] ?? null) ? $data['medias'] : [];

/** @var array<int, int> $usages */
$usages = is_array($data['usages'] ?? null) ? $data['usages'] : [];

/** @var list<array{nom: string, motif: string}> $refus */
$refus = is_array($data['refus'] ?? null) ? $data['refus'] : [];

?>
<div class="admin-page">
    <h1>Médiathèque</h1>

    <?php if (is_string($data['succes'] ?? null)) : ?>
    <p class="succes" role="status"><?= e($data['succes']) ?></p>
    <?php endif; ?>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <?php if ($refus !== []) : ?>
    <div class="erreur" role="alert">
        <p>Ces fichiers n’ont pas été acceptés :</p>
        <ul>
        <?php foreach ($refus as $refuse) : ?>
            <li><strong><?= e($refuse['nom']) ?></strong> — <?= e($refuse['motif']) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/medias') ?>"
          enctype="multipart/form-data" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="images">Ajouter des images</label>
            <input type="file" id="images" name="images[]" multiple
                   accept="image/jpeg,image/png,image/webp" required>
            <span class="champ-aide">
                JPEG, PNG ou WebP. 25 Mo au maximum par fichier. Les images sont
                ré-encodées à l’enregistrement : les données de l’appareil photo,
                géolocalisation comprise, sont supprimées.
            </span>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Téléverser</button>
        </p>
    </form>

    <h2><?= e($data['total'] ?? 0) ?> image(s)</h2>

    <?php if ($medias === []) : ?>
    <p class="aide">La médiathèque est vide.</p>
    <?php else : ?>
    <ul class="grille-medias">
    <?php foreach ($medias as $media) : ?>
        <li>
            <?php // Cliquer sur la vignette ouvre la fiche : edition, remplacement, recadrage. ?>
            <a class="vignette-media-lien" href="<?= attr($base . '/admin/medias/' . $media['id']) ?>">
                <figure class="vignette-media">
                    <img src="<?= attr($url->media($media['public_basename'] . '-320.jpg')) ?>"
                         alt="<?= attr($media['original_name'] ?? '') ?>"
                         width="320" loading="lazy">

                    <figcaption>
                        <?= e($media['original_name'] ?? 'Sans nom') ?><br>
                        <?= e($media['width']) ?> × <?= e($media['height']) ?> px
                        <?php if (($usages[$media['id']] ?? 0) > 0) : ?>
                        <br><span class="pastille">utilisée <?= e($usages[$media['id']]) ?> fois</span>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            </a>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
